<div class="DataListWrap">
    <h2 class="H"><?= $this->data('TabTitle') ?></h2>
    <ul class="DataList SearchResults">
    <?php
    if (sizeof($this->data('Mentions'))) {
        require(viewLocation('mentioned', '', 'plugins/mentioned'));
        echo $this->Pager->toString('more');
    } else {
        echo '<li class="Item Empty">'.t('This user has not been mentioned yet.').'</li>';
    }
    ?>
    </ul>
</div>