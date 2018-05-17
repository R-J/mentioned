<?php
$mentioned = $this->data('Mentioned');
foreach ($mentioned as $post) {
// /discussion/comment/177/#Comment_177
// /discussion/56/regen-fiel-aber-an-ihr-vom-wohnungswechsel-abhielt-war#latest
    if (isset($post->CommentID)) {
        $url = url("/discussion/comment/{$post->CommentID}/#Comment_{$post->CommentID}");
    } else {
        $name = Gdn_Format::url($post->Name);
        $url = url("/discussion/{$post->DiscussionID}/{$name}#latest");
    }
    $user = Gdn::userModel()->getID($post->InsertUserID);
?>
    <li class="Item Item-Search">
        <h3><?= anchor(htmlspecialchars($post->Name), $url) ?></h3>
        <div class="Item-Body Media">
            <div class="Media-Body">
                <div class="Meta">
                    <span class="MItem-Author">
                        <?= sprintf(t('by %s'), userAnchor($user)) ?>
                    </span>
                    <?= bullet(' ') ?>
                    <span class="MItem-DateInserted">
                        <?= Gdn_Format::date($post->DateInserted, 'html') ?>
                    </span>

                    <?php if (isset($post->Breadcrumbs)): ?>
                    <?= bullet(' ') ?>
                    <span class="MItem-Location">
                        <?= Gdn_Theme::breadcrumbs($post->Breadcrumbs, false) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="Summary">
                    <?= sliceString(Gdn_Format::text(Gdn_Format::to($post->Body, $post->Format), false), 250) ?>
                </div>
            </div>
        </div>
    </li>
<?php
}
