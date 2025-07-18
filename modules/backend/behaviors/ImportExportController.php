<?php

namespace Backend\Behaviors;

use Backend\Behaviors\ImportExportController\TranscodeFilter;
use Backend\Classes\ControllerBehavior;
use Backend\Facades\Backend;
use Backend\Facades\BackendAuth;
use Exception;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Response;
use League\Csv\EscapeFormula as CsvEscapeFormula;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement as CsvStatement;
use League\Csv\Writer as CsvWriter;
use SplTempFileObject;
use Winter\Storm\Exception\ApplicationException;
use Winter\Storm\Support\Str;

/**
 * Adds features for importing and exporting data.
 *
 * This behavior is implemented in the controller like so:
 *
 *     public $implement = [
 *         \Backend\Behaviors\ImportExportController::class,
 *     ];
 *
 *     public $importExportConfig = 'config_import_export.yaml';
 *
 * The `$importExportConfig` property makes reference to the configuration
 * values as either a YAML file, located in the controller view directory,
 * or directly as a PHP array.
 *
 * @package winter\wn-backend-module
 * @author Alexey Bobkov, Samuel Georges
 */
class ImportExportController extends ControllerBehavior
{
    /**
     * @var array Configuration values that must exist when applying the primary config file.
     */
    protected $requiredConfig = [];

    /**
     * @var array Visible actions in context of the controller
     */
    protected $actions = ['import', 'export', 'download'];

    /**
     * @var Model Import model
     */
    public $importModel;

    /**
     * @var array Import column configuration.
     */
    public $importColumns;

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for uploading import file.
     */
    protected $importUploadFormWidget;

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for specifying import options.
     */
    protected $importOptionsFormWidget;

    /**
     * @var Model Export model
     */
    public $exportModel;

    /**
     * @var array Export column configuration.
     */
    public $exportColumns;

    /**
     * @var string File name used for export output.
     */
    protected $exportFileName = 'export.csv';

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for standard export options.
     */
    protected $exportFormatFormWidget;

    /**
     * @var Backend\Classes\WidgetBase Reference to the widget used for custom export options.
     */
    protected $exportOptionsFormWidget;

    /**
     * @var mixed Configuration for this behaviour
     */
    public $importExportConfig = 'config_import_export.yaml';

    /**
     * Behavior constructor
     * @param Backend\Classes\Controller $controller
     */
    public function __construct($controller)
    {
        parent::__construct($controller);

        /*
         * Build configuration
         */
        $this->config = $this->makeConfig($controller->importExportConfig ?: $this->importExportConfig, $this->requiredConfig);

        /*
         * Process config
         */
        if ($exportFileName = $this->getConfig('export[fileName]')) {
            $this->exportFileName = $exportFileName;
        }

        /*
         * Import form widgets
         */
        if ($this->importUploadFormWidget = $this->makeImportUploadFormWidget()) {
            $this->importUploadFormWidget->bindToController();
        }

        if ($this->importOptionsFormWidget = $this->makeImportOptionsFormWidget()) {
            $this->importOptionsFormWidget->bindToController();
        }

        /*
         * Export form widgets
         */
        if ($this->exportFormatFormWidget = $this->makeExportFormatFormWidget()) {
            $this->exportFormatFormWidget->bindToController();
        }

        if ($this->exportOptionsFormWidget = $this->makeExportOptionsFormWidget()) {
            $this->exportOptionsFormWidget->bindToController();
        }
    }

    //
    // Controller actions
    //

    public function import()
    {
        if (!$this->userHasAccess('import')) {
            abort(403);
        }

        $this->addJs('js/winter.import.js', 'core');
        $this->addCss('css/import.css', 'core');

        $this->controller->pageTitle = $this->controller->pageTitle
            ?: Lang::get($this->getConfig('import[title]', 'Import records'));

        $this->prepareImportVars();
    }

    public function export()
    {
        if (!$this->userHasAccess('export')) {
            abort(403);
        }

        if ($response = $this->checkUseListExportMode()) {
            return $response;
        }

        $this->addJs('js/winter.export.js', 'core');
        $this->addCss('css/export.css', 'core');

        $this->controller->pageTitle = $this->controller->pageTitle
            ?: Lang::get($this->getConfig('export[title]', 'Export records'));

        $this->prepareExportVars();
    }

    public function download($name, $outputName = null)
    {
        $this->controller->pageTitle = $this->controller->pageTitle
            ?: Lang::get($this->getConfig('export[title]', 'Export records'));

        return $this->exportGetModel()->download($name, $outputName);
    }

    //
    // Importing AJAX
    //

    public function onImport()
    {
        try {
            $model = $this->importGetModel();
            $matches = post('column_match', []);

            if ($optionData = post('ImportOptions')) {
                $model->fill($optionData);
            }

            $importOptions = $this->getFormatOptionsFromPost();
            $importOptions['sessionKey'] = $this->importUploadFormWidget->getSessionKey();
            $importOptions['firstRowTitles'] = post('first_row_titles', false);

            $model->import($matches, $importOptions);

            $this->vars['importResults'] = $model->getResultStats();
            $this->vars['returnUrl'] = $this->getRedirectUrlForType('import');
        }
        catch (MassAssignmentException $ex) {
            $this->controller->handleError(new ApplicationException(Lang::get(
                'backend::lang.model.mass_assignment_failed',
                ['attribute' => $ex->getMessage()]
            )));
        }
        catch (Exception $ex) {
            $this->controller->handleError($ex);
        }

        $this->vars['sourceIndexOffset'] = $this->getImportSourceIndexOffset($importOptions['firstRowTitles']);

        return $this->importExportMakePartial('import_result_form');
    }

    public function onImportLoadForm()
    {
        try {
            $this->checkRequiredImportColumns();
        }
        catch (Exception $ex) {
            $this->controller->handleError($ex);
        }

        return $this->importExportMakePartial('import_form');
    }

    public function onImportLoadColumnSampleForm()
    {
        if (($columnId = post('file_column_id', false)) === false) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.missing_column_id_error'));
        }

        $columns = $this->getImportFileColumns();
        if (!array_key_exists($columnId, $columns)) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.unknown_column_error'));
        }

        $path = $this->getImportFilePath();
        $reader = $this->createCsvReader($path);

        if (post('first_row_titles')) {
            $reader->setHeaderOffset(1);
        }

        $result = (new CsvStatement())->limit(50)->process($reader)->fetchColumn((int) $columnId);
        $data = iterator_to_array($result, false);

        /*
         * Clean up data
         */
        foreach ($data as $index => $sample) {
            $data[$index] = Str::limit($sample, 100);
            if (!strlen($data[$index])) {
                unset($data[$index]);
            }
        }

        $this->vars['columnName'] = array_get($columns, $columnId);
        $this->vars['columnData'] = $data;

        return $this->importExportMakePartial('column_sample_form');
    }

    //
    // Importing
    //

    /**
     * Prepares the view data.
     * @return void
     */
    public function prepareImportVars()
    {
        $this->vars['importUploadFormWidget'] = $this->importUploadFormWidget;
        $this->vars['importOptionsFormWidget'] = $this->importOptionsFormWidget;
        $this->vars['importDbColumns'] = $this->getImportDbColumns();
        $this->vars['importFileColumns'] = $this->getImportFileColumns();

        // Make these variables available to widgets
        $this->controller->vars += $this->vars;
    }

    public function importRender()
    {
        return $this->importExportMakePartial('container_import');
    }

    public function importGetModel()
    {
        return $this->getModelForType('import');
    }

    protected function getImportDbColumns()
    {
        if ($this->importColumns !== null) {
            return $this->importColumns;
        }

        $columnConfig = $this->getConfig('import[list]');
        $columns = $this->makeListColumns($columnConfig);

        if (empty($columns)) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.empty_import_columns_error'));
        }

        return $this->importColumns = $columns;
    }

    protected function getImportFileColumns()
    {
        if (!$path = $this->getImportFilePath()) {
            return null;
        }

        $reader = $this->createCsvReader($path);
        $firstRow = $reader->fetchOne(0);

        if (!post('first_row_titles')) {
            array_walk($firstRow, function (&$value, $key) {
                $value = 'Column #'.($key + 1);
            });
        }

        /*
         * Prevents unfriendly error to be thrown due to bad encoding at response time.
         */
        if (json_encode($firstRow) === false) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.encoding_not_supported_error'));
        }

        return $firstRow;
    }

    /**
     * Get the index offset to add to the reported row number in status messages
     *
     * @param bool $firstRowTitles Whether or not the first row contains column titles
     * @return int $offset
     */
    protected function getImportSourceIndexOffset($firstRowTitles)
    {
        return $firstRowTitles ? 2 : 1;
    }

    protected function makeImportUploadFormWidget()
    {
        if (!$this->getConfig('import')) {
            return null;
        }

        $widgetConfig = $this->makeConfig('~/modules/backend/behaviors/importexportcontroller/partials/fields_import.yaml');
        $widgetConfig->model = $this->importGetModel();
        $widgetConfig->alias = 'importUploadForm';

        $widget = $this->makeWidget('Backend\Widgets\Form', $widgetConfig);

        $widget->bindEvent('form.beforeRefresh', function ($holder) {
            $holder->data = [];
        });

        return $widget;
    }

    protected function makeImportOptionsFormWidget()
    {
        $widget = $this->makeOptionsFormWidgetForType('import');

        if (!$widget && $this->importUploadFormWidget) {
            $stepSection = $this->importUploadFormWidget->getField('step3_section');
            $stepSection->hidden = true;
        }

        return $widget;
    }

    protected function getImportFilePath()
    {
        return $this
            ->importGetModel()
            ->getImportFilePath($this->importUploadFormWidget->getSessionKey());
    }

    public function importIsColumnRequired($columnName)
    {
        $model = $this->importGetModel();

        return $model->isAttributeRequired($columnName);
    }

    protected function checkRequiredImportColumns()
    {
        if (!$matches = post('column_match', [])) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.match_some_column_error'));
        }

        $dbColumns = $this->getImportDbColumns();
        foreach ($dbColumns as $column => $label) {
            if (!$this->importIsColumnRequired($column)) {
                continue;
            }

            $found = false;
            foreach ($matches as $matchedColumns) {
                if (in_array($column, $matchedColumns)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new ApplicationException(Lang::get('backend::lang.import_export.required_match_column_error', [
                    'label' => Lang::get($label)
                ]));
            }
        }
    }

    //
    // Exporting AJAX
    //

    public function onExport()
    {
        try {
            $model = $this->exportGetModel();
            $columns = $this->processExportColumnsFromPost();

            if ($optionData = post('ExportOptions')) {
                $model->fill($optionData);
            }

            $exportOptions = $this->getFormatOptionsFromPost();
            $exportOptions['sessionKey'] = $this->exportFormatFormWidget->getSessionKey();

            $reference = $model->export($columns, $exportOptions);
            $fileUrl = $this->controller->actionUrl(
                'download',
                $reference.'/'.$this->exportFileName
            );

            $this->vars['fileUrl'] = $fileUrl;
            $this->vars['returnUrl'] = $this->getRedirectUrlForType('export');
        }
        catch (MassAssignmentException $ex) {
            $this->controller->handleError(new ApplicationException(Lang::get(
                'backend::lang.model.mass_assignment_failed',
                ['attribute' => $ex->getMessage()]
            )));
        }
        catch (Exception $ex) {
            $this->controller->handleError($ex);
        }

        return $this->importExportMakePartial('export_result_form');
    }

    public function onExportLoadForm()
    {
        return $this->importExportMakePartial('export_form');
    }

    //
    // Exporting
    //

    /**
     * Prepares the view data.
     * @return void
     */
    public function prepareExportVars()
    {
        $this->vars['exportFormatFormWidget'] = $this->exportFormatFormWidget;
        $this->vars['exportOptionsFormWidget'] = $this->exportOptionsFormWidget;
        $this->vars['exportColumns'] = $this->getExportColumns();

        // Make these variables available to widgets
        $this->controller->vars += $this->vars;
    }

    public function exportRender()
    {
        return $this->importExportMakePartial('container_export');
    }

    public function exportGetModel()
    {
        return $this->getModelForType('export');
    }

    protected function getExportColumns()
    {
        if ($this->exportColumns !== null) {
            return $this->exportColumns;
        }

        $columnConfig = $this->getConfig('export[list]');
        $columns = $this->makeListColumns($columnConfig);

        if (empty($columns)) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.empty_export_columns_error'));
        }

        return $this->exportColumns = $columns;
    }

    protected function makeExportFormatFormWidget()
    {
        if (!$this->getConfig('export') || $this->getConfig('export[useList]')) {
            return null;
        }

        $widgetConfig = $this->makeConfig('~/modules/backend/behaviors/importexportcontroller/partials/fields_export.yaml');
        $widgetConfig->model = $this->exportGetModel();
        $widgetConfig->alias = 'exportUploadForm';

        $widget = $this->makeWidget('Backend\Widgets\Form', $widgetConfig);

        $widget->bindEvent('form.beforeRefresh', function ($holder) {
            $holder->data = [];
        });

        return $widget;
    }

    protected function makeExportOptionsFormWidget()
    {
        $widget = $this->makeOptionsFormWidgetForType('export');

        if (!$widget && $this->exportFormatFormWidget) {
            $stepSection = $this->exportFormatFormWidget->getField('step3_section');
            $stepSection->hidden = true;
        }

        return $widget;
    }

    protected function processExportColumnsFromPost()
    {
        $visibleColumns = post('visible_columns', []);
        $columns = post('export_columns', []);

        foreach ($columns as $key => $columnName) {
            if (!isset($visibleColumns[$columnName])) {
                unset($columns[$key]);
            }
        }

        $result = [];
        $definitions = $this->getExportColumns();

        foreach ($columns as $column) {
            $result[$column] = array_get($definitions, $column, '???');
        }

        return $result;
    }

    //
    // ListController integration
    //

    protected function checkUseListExportMode()
    {
        if (!$useList = $this->getConfig('export[useList]')) {
            return false;
        }

        if (!$this->controller->isClassExtendedWith(\Backend\Behaviors\ListController::class)) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.behavior_missing_uselist_error'));
        }

        if (is_array($useList)) {
            $listDefinition = array_get($useList, 'definition');
        }
        else {
            $listDefinition = $useList;
        }

        return $this->exportFromList($listDefinition);
    }

    /**
     * Outputs the list results as a CSV export.
     * @param string $definition
     * @param array $options
     * @return void
     */
    public function exportFromList($definition = null, $options = [])
    {
        $lists = $this->controller->makeLists();

        $widget = $lists[$definition] ?? reset($lists);

        /*
         * Parse options
         */
        $defaultOptions = [
            'fileName' => $this->exportFileName,
            'delimiter' => $this->getConfig('defaultFormatOptions[delimiter]', ','),
            'enclosure' => $this->getConfig('defaultFormatOptions[enclosure]', '"'),
            'escape' => $this->getConfig('defaultFormatOptions[escape]', '\\'),
        ];

        $options = array_merge($defaultOptions, $options);

        $filename = filter_var($options['fileName'], FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);

        /*
         * Prepare CSV
         */
        $csv = CsvWriter::createFromFileObject(new SplTempFileObject);
        $csv->setOutputBOM(CsvWriter::BOM_UTF8);
        $csv->setDelimiter($options['delimiter']);
        $csv->setEnclosure($options['enclosure']);
        $csv->setEscape($options['escape']);
        $csv->addFormatter(new CsvEscapeFormula());

        /*
         * Add headers
         */
        $headers = [];
        $columns = $widget->getVisibleColumns();
        foreach ($columns as $column) {
            $headers[] = $widget->getHeaderValue($column);
        }
        $csv->insertOne($headers);

        /*
         * Add records
         */
        $getter = $this->getConfig('export[useList][raw]', false)
            ? 'getColumnValueRaw'
            : 'getColumnValue';

        $query = $widget->prepareQuery();
        $results = $query->get();

        if ($event = $widget->fireSystemEvent('backend.list.extendRecords', [&$results])) {
            $results = $event;
        }

        foreach ($results as $result) {
            $record = [];
            foreach ($columns as $column) {
                $value = $widget->$getter($result, $column);
                if (is_array($value)) {
                    $value = implode('|', $value);
                }
                $record[] = $value;
            }

            $csv->insertOne($record);
        }

        /*
         * Response
         */
        $response = Response::make();
        $response->header('Content-Type', 'text/csv');
        $response->header('Content-Transfer-Encoding', 'binary');
        $response->header('Content-Disposition', sprintf('%s; filename="%s"', 'attachment', $filename));
        $response->setContent((string) $csv);
        return $response;
    }

    //
    // Helpers
    //

    /**
     * Controller accessor for making partials within this behavior.
     * @param string $partial
     * @param array $params
     * @return string Partial contents
     */
    public function importExportMakePartial($partial, $params = [])
    {
        $contents = $this->controller->makePartial('import_export_'.$partial, $params + $this->vars, false);

        if (!$contents) {
            $contents = $this->makePartial($partial, $params);
        }

        return $contents;
    }

    /**
     * Check if the current user has access to the provided import/export action
     */
    public function userHasAccess(string $type): bool
    {
        if (
            ($permissions = $this->getConfig($type.'[permissions]')) &&
            (!BackendAuth::getUser()->hasAnyAccess((array) $permissions))
        ) {
            return false;
        }

        return true;
    }

    protected function makeOptionsFormWidgetForType($type)
    {
        if (!$this->getConfig($type)) {
            return null;
        }

        if ($fieldConfig = $this->getConfig($type.'[form]')) {
            $widgetConfig = $this->makeConfig($fieldConfig);
            $widgetConfig->model = $this->getModelForType($type);
            $widgetConfig->alias = $type.'OptionsForm';
            $widgetConfig->arrayName = ucfirst($type).'Options';

            return $this->makeWidget('Backend\Widgets\Form', $widgetConfig);
        }

        return null;
    }

    protected function getModelForType($type)
    {
        $cacheProperty = $type.'Model';

        if ($this->{$cacheProperty} !== null) {
            return $this->{$cacheProperty};
        }

        $modelClass = $this->getConfig($type.'[modelClass]');
        if (!$modelClass) {
            throw new ApplicationException(Lang::get('backend::lang.import_export.missing_model_class_error', [
                'type' => $type
            ]));
        }

        return $this->{$cacheProperty} = new $modelClass;
    }

    protected function makeListColumns($config)
    {
        $config = $this->makeConfig($config);

        if (!isset($config->columns) || !is_array($config->columns)) {
            return null;
        }

        $result = [];
        foreach ($config->columns as $attribute => $column) {
            if (is_array($column)) {
                $result[$attribute] = array_get($column, 'label', $attribute);
            }
            else {
                $result[$attribute] = $column ?: $attribute;
            }
        }

        return $result;
    }

    protected function getRedirectUrlForType($type)
    {
        $redirect = $this->getConfig($type.'[redirect]');

        if ($redirect !== null) {
            return $redirect ? Backend::url($redirect) : 'javascript:;';
        }

        return $this->controller->actionUrl($type);
    }

    /**
     * Create a new CSV reader with options selected by the user
     * @param string $path
     *
     * @return CsvReader
     */
    protected function createCsvReader($path)
    {
        $reader = CsvReader::createFromPath($path);
        $options = $this->getFormatOptionsFromPost();

        if ($options['delimiter'] !== null) {
            $reader->setDelimiter($options['delimiter']);
        }

        if ($options['enclosure'] !== null) {
            $reader->setEnclosure($options['enclosure']);
        }

        if ($options['escape'] !== null) {
            $reader->setEscape($options['escape']);
        }

        if (
            $options['encoding'] !== null &&
            $reader->supportsStreamFilter()
        ) {
            $reader->addStreamFilter(sprintf(
                '%s%s:%s',
                TranscodeFilter::FILTER_NAME,
                strtolower($options['encoding']),
                'utf-8'
            ));
        }

        return $reader;
    }

    /**
     * Returns the file format options from postback. This method
     * can be used to define presets.
     * @return array
     */
    protected function getFormatOptionsFromPost()
    {
        $presetMode = post('format_preset');

        $options = [
            'delimiter' => $this->getConfig('defaultFormatOptions[delimiter]'),
            'enclosure' => $this->getConfig('defaultFormatOptions[enclosure]'),
            'escape' => $this->getConfig('defaultFormatOptions[escape]'),
            'encoding' => $this->getConfig('defaultFormatOptions[encoding]'),
        ];

        if ($presetMode == 'custom') {
            $options['delimiter'] = post('format_delimiter');
            $options['enclosure'] = post('format_enclosure');
            $options['escape'] = post('format_escape');
            $options['encoding'] = post('format_encoding');
        }

        return $options;
    }
}
