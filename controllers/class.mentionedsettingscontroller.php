<?php
/**
 * Create a settings screen for mentioned plugin.
  */
class MentionedSettingsController extends Gdn_Plugin {
    /**
     * Main function that shows settings screen.
     *
     * @param settingsController $sender Sending object instance.
     * @return void.
     */
    public function index($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->addSideMenu('dashboard/settings/plugins');

        $sender->setData('Title', t('Mentioned Settings'));
        $sender->setData('Description', 'Allows you to index old discussions and comments so that mentions made in the passed are referenced in the users profiles. Using high values might lead to a timeout.');

        // Test if form has been submitted.
        if ($sender->Form->authenticatedPostBack()) {

            // require(__DIR__.'/../models/class.mentionedmodel.php');
            $mentionedModel = new MentionedModel();

            $formPostValues = $sender->Form->formValues();
            // Index discussions.
            if (isset($formPostValues['Index_Discussions'])) {
                // Memorize number of discussions to index.
                $countDiscussions = intval($formPostValues['CountDiscussions']);
                saveToConfig('mentioned.CountDiscussions', $countDiscussions);

                // Get discussions from db.
                $lastDiscussionID = c('mentioned.LastDiscussionID', 0);
                $discussions = Gdn::sql()->getWhere(
                    'Discussion',
                    ['DiscussionID >=' => $lastDiscussionID],
                    'DiscussionID',
                    'asc',
                    $countDiscussions
                );
                $count = 0;
                foreach ($discussions as $discussion) {
                    $mentionedModel->save(
                        [
                            'ForeignType' => 'Discussion',
                            'DiscussionID' => $discussion->DiscussionID,
                            'CategoryID' => $discussion->CategoryID,
                            'CommentID' => 0,
                            'DateInserted' => $discussion->DateInserted,
                            'MentionedUserNames' => getMentions($discussion->Body)
                        ]
                    );

                    // Keep track of indexed discussions so we do not have to
                    // start from zero if server faces a timeout.
                    $lastDiscussionID = $discussion->DiscussionID;
                    $count++;
                    if ($count/10 == intval($count/10)) {
                        saveToConfig('mentioned.LastDiscussionID', $lastDiscussionID);
                    }
                }
                saveToConfig('mentioned.LastDiscussionID', $lastDiscussionID);
                $sender->informMessage($count.' discussions have been indexed');
            } elseif (isset($formPostValues['Index_Comments'])) {
                // Memorize number of comments to index.
                $countComments = intval($formPostValues['CountComments']);
                saveToConfig('mentioned.CountComments', $countComments);

                // Get comments from db.
                $lastCommentID = c('mentioned.LastCommentID', 0);
                $comments = Gdn::sql()
                    ->select('d.DiscussionID, d.CategoryID, c.CommentID, c.Body, c.DateInserted')
                    ->from('Comment c')
                    ->join('Discussion d', 'c.DiscussionID = d.DiscussionID', 'right')
                    ->where('c.CommentID >=', $lastCommentID)
                    ->orderBy('CommentID', 'asc')
                    ->limit($countComments)
                    ->get()
                    ->resultObject();

                $count = 0;
                foreach ($comments as $comment) {
                    $mentionedModel->save(
                        [
                            'ForeignType' => 'Comment',
                            'DiscussionID' => $comment->DiscussionID,
                            'CategoryID' => $comment->CategoryID,
                            'CommentID' => $comment->CommentID,
                            'DateInserted' => $comment->DateInserted,
                            'MentionedUserNames' => getMentions($comment->Body)
                        ]
                    );
                    // Keep track of indexed comments so we do not have to
                    // start from zero if server faces a timeout.
                    $lastCommentID = $comment->CommentID;
                    $count++;
                    if ($count/10 == intval($count/10)) {
                        saveToConfig('mentioned.LastCommentID', $lastCommentID);
                    }
                }
                saveToConfig('mentioned.LastCommentID', $lastCommentID);
                $sender->informMessage($count.' comments have been indexed');
            }
        }

        $sender->Form->setValue('CountComments', c('mentioned.CountComments', 500));
        $sender->Form->setValue('CountDiscussions', c('mentioned.CountDiscussions', 500));

        $sender->render(viewLocation('settings', '', 'plugins/mentioned'));
    }
}
