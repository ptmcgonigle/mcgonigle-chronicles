<tr>
    <?php if ($showCheckboxes && count($records)): ?>
        <th class="list-checkbox">
            <div class="checkbox custom-checkbox nolabel">
                <input type="checkbox" id="<?= $this->getId('checkboxAll') ?>" />
                <label for="<?= $this->getId('checkboxAll') ?>"></label>
            </div>
        </th>
    <?php endif ?>

    <?php if ($showTree): ?>
        <th class="list-tree">
            <span></span>
        </th>
    <?php endif ?>

    <?php foreach ($columns as $key => $column): ?>
        <?php if ($showSorting && $column->sortable): ?>
            <th
                <?php if ($column->width): ?>
                    style="width: <?= $column->width ?>"
                <?php endif ?>
                class="sortable <?= $this->sortColumn==$column->columnName?'sort-'.$this->sortDirection.' active':'' ?> list-cell-name-<?= $column->getName() ?> list-cell-type-<?= $column->type ?> <?= $column->getAlignClass() ?> <?= $column->headCssClass ?>"
                >
                <a
                    href="javascript:;"
                    data-request="<?= $this->getEventHandler('onSort') ?>"
                    data-stripe-load-indicator
                    data-request-data="sortColumn: '<?= $column->columnName ?>', page: <?= $pageCurrent ?>">
                    <?= $this->getHeaderValue($column) ?>
                </a>
            </th>
        <?php else: ?>
            <th
                <?php if ($column->width): ?>
                    style="width: <?= $column->width ?>"
                <?php endif ?>
                class="list-cell-name-<?= $column->getName() ?> list-cell-type-<?= $column->type ?> <?= $column->getAlignClass() ?> <?= $column->headCssClass ?>"
                >
                <span><?= $this->getHeaderValue($column) ?></span>
            </th>
        <?php endif ?>
    <?php endforeach ?>

    <?php if ($showSetup): ?>
        <th class="list-setup">
            <a href="javascript:;"
                id="<?= $this->getId('setupButton') ?>"
                title="<?= e(trans('backend::lang.list.setup_title')) ?>"
                data-control="popup"
                data-handler="<?= $this->getEventHandler('onLoadSetup') ?>"></a>
        </th>
    <?php endif ?>
</tr>
