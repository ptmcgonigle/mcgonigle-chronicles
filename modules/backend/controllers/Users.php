<?php namespace Backend\Controllers;

use Mail;
use Lang;
use Flash;
use Backend;
use Redirect;
use Response;
use BackendMenu;
use BackendAuth;
use Backend\Models\UserGroup;
use Backend\Classes\Controller;
use System\Classes\SettingsManager;

/**
 * Backend user controller
 *
 * @package winter\wn-backend-module
 * @author Alexey Bobkov, Samuel Georges
 *
 */
class Users extends Controller
{
    /**
     * @var array Extensions implemented by this controller.
     */
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
    ];

    /**
     * @var array Permissions required to view this page.
     */
    public $requiredPermissions = ['backend.manage_users'];

    /**
     * @var string HTML body tag class
     */
    public $bodyClass = 'compact-container';

    public $formLayout = 'sidebar';

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();

        if ($this->action == 'myaccount') {
            $this->requiredPermissions = null;
        }

        BackendMenu::setContext('Winter.System', 'system', 'users');
        SettingsManager::setContext('Winter.System', 'administrators');
    }

    /**
     * Extends the list query to hide superusers if the current user is not a superuser themselves
     */
    public function listExtendQuery($query)
    {
        if (!$this->user->isSuperUser()) {
            $query->where('is_superuser', false);
        }
    }

    /**
     * Prevents non-superusers from even seeing the is_superuser filter
     */
    public function listFilterExtendScopes($filterWidget)
    {
        if (!$this->user->isSuperUser()) {
            $filterWidget->removeScope('is_superuser');
        }
    }

    /**
     * Strike out deleted records
     */
    public function listInjectRowClass($record, $definition = null)
    {
        if ($record->trashed()) {
            return 'strike';
        }
    }

    /**
     * Extends the form query to prevent non-superusers from accessing superusers at all
     */
    public function formExtendQuery($query)
    {
        if (!$this->user->isSuperUser()) {
            $query->where('is_superuser', false);
        }

        // Ensure soft-deleted records can still be managed
        $query->withTrashed();
    }

    /**
     * Update controller
     */
    public function update($recordId, $context = null)
    {
        // Users cannot edit themselves, only use My Settings
        if ($context != 'myaccount' && $recordId == $this->user->id) {
            return Backend::redirect('backend/users/myaccount');
        }

        return $this->asExtension('FormController')->update($recordId, $context);
    }

    /**
     * Handle restoring users
     */
    public function update_onRestore($recordId)
    {
        $this->formFindModelObject($recordId)->restore();

        Flash::success(Lang::get('backend::lang.form.restore_success', ['name' => Lang::get('backend::lang.user.name')]));

        return Redirect::refresh();
    }

    /**
     * Impersonate this user
     */
    public function update_onImpersonateUser($recordId)
    {
        if (!$this->user->hasAccess('backend.impersonate_users')) {
            return Response::make(Lang::get('backend::lang.page.access_denied.label'), 403);
        }

        $model = $this->formFindModelObject($recordId);

        BackendAuth::impersonate($model);

        Flash::success(Lang::get('backend::lang.account.impersonate_success'));

        return Backend::redirect('backend/users/myaccount');
    }

    /**
     * Unsuspend this user
     */
    public function update_onUnsuspendUser($recordId)
    {
        $model = $this->formFindModelObject($recordId);

        $model->unsuspend();

        Flash::success(Lang::get('backend::lang.account.unsuspend_success'));

        return Redirect::refresh();
    }

    /**
     * My Settings controller
     */
    public function myaccount()
    {
        SettingsManager::setContext('Winter.Backend', 'myaccount');

        $this->pageTitle = 'backend::lang.myaccount.menu_label';
        return $this->update($this->user->id, 'myaccount');
    }

    /**
     * Proxy update onSave event
     */
    public function myaccount_onSave()
    {
        $result = $this->asExtension('FormController')->update_onSave($this->user->id, 'myaccount');

        /*
         * If the password or login name has been updated, reauthenticate the user
         */
        $loginChanged = $this->user->login != post('User[login]');
        $passwordChanged = strlen(post('User[password]'));
        if ($loginChanged || $passwordChanged) {
            BackendAuth::login($this->user->reload(), true);
        }

        return $result;
    }

    /**
     * Add available permission fields to the User form.
     * Mark default groups as checked for new Users.
     */
    public function formExtendFields($form)
    {
        if ($form->getContext() == 'myaccount') {
            return;
        }

        if (!$this->user->isSuperUser()) {
            $form->removeField('is_superuser');
        }

        /*
         * Add permissions tab
         */
        $form->addTabFields($this->generatePermissionsField());

        /*
         * Mark default groups
         */
        if (!$form->model->exists) {
            $defaultGroupIds = UserGroup::where('is_new_user_default', true)->lists('id');

            $groupField = $form->getField('groups');
            if ($groupField) {
                $groupField->value = $defaultGroupIds;
            }
        }
    }

    /**
     * Adds the permissions editor widget to the form.
     * @return array
     */
    protected function generatePermissionsField()
    {
        return [
            'permissions' => [
                'tab' => 'backend::lang.user.permissions',
                'type' => 'Backend\FormWidgets\PermissionEditor',
                'trigger' => [
                    'action' => 'disable',
                    'field' => 'is_superuser',
                    'condition' => 'checked'
                ]
            ]
        ];
    }

    /**
     * Send password reset mail
     */
    public function update_onManualPasswordReset($recordId)
    {
        $user = $this->formFindModelObject($recordId);

        if ($user) {
            $code = $user->getResetPasswordCode();
            $link = Backend::url('backend/auth/reset/' . $user->id . '/' . $code);

            $data = [
                'name' => $user->full_name,
                'link' => $link,
            ];

            Mail::send('backend::mail.restore', $data, function ($message) use ($user) {
                $message->to($user->email, $user->full_name)->subject(trans('backend::lang.account.password_reset'));
            });
        }

        Flash::success(Lang::get('backend::lang.account.manual_password_reset_success'));

        return Redirect::refresh();
    }
}
