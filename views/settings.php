<h1><?= $this->title() ?></h1>
<div class="Info"><?= $this->data('Description') ?></div>
<?= $this->Form->open(), $this->Form->errors() ?>
<ul>
    <li>
        <?= $this->Form->textBox('CountDiscussions', ['maxlength' => '4', 'class' => 'SmallInput']) ?>
        <?= $this->Form->button('Index Discussions', ['type' => 'submit']) ?>
    </li>
    <li>
        <?= $this->Form->textBox('CountComments', ['maxlength' => '4', 'class' => 'SmallInput']) ?>
        <?= $this->Form->button('Index Comments', ['type' => 'submit']) ?>
    </li>
</ul>
<?= $this->Form->close() ?>

<?php
/*
echo
    wrap($this->title(), 'h1'),
    wrap($this->description(), 'div', ['class' => 'Info']),
    $this->Form->open(),
    $this->Form->errors(),
    '<p>Index ',
    $this->Form->textBox('PostCount', ['maxlength' => '4', 'class' => 'InputBox']),
    $this->Form->dropDown('PostType', ['Comments' => 'comments', 'Discussions' => 'dicsussions']),
    $this->Form->button('Go', ['type' => 'submit']),
    '</p>',
    $this->Form->close();
*/