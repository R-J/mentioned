<?php

class MentionedModel extends Gdn_Model {
    /**
     * Class constructor. Defines the related database table name.
     *
     * @param string $name An optional parameter for the name of the table this
     * model represents.
     */
    public function __construct($name = '') {
        if ($name === '') {
            $name = 'Mentioned';
        }
        parent::__construct($name);
    }
    /**
     * Save mentioning to a separate table.
     *
     * @param array $values Record data to save.
     * @param array $settings If custom options would be needed, that woiuld be the place.
     *
     * @return boolean Whether the action has was successful or not.
     */
    public function save($values, $settings = false) {
        if ($values['MentionedUserNames'] === []) {
            return false;
        }
        $mentionedUserNames = $values['MentionedUserNames'];
        unset($values['MentionedUserNames']);

        // Clean old entries.
        $this->delete($values);

        // Gather values to insert into db.
        $sqlValues = [];
        foreach ($mentionedUserNames as $userName) {
            $user = Gdn::userModel()->getByUsername($userName);
            if ($user) {
                Gdn::userModel()->setField($user->UserID, 'CountMentioned', $user->CountMentioned + 1);
                $this->insert(array_merge($values, ['UserID' => $user->UserID]));
            }
        }
        return true;
    }

    /**
     * Gets the discussions/comments where a user has been mentioned.
     *
     * @param int $userID [description]
     * @param int $limit  [description]
     * @param  int $offset [description]
     *
     * @return void.
     */
    public function getByUserID($userID, $limit, $offset = 0) {
        $cacheKey = "mentioned_{$userID}_{$limit}_{$offset}";
        $posts = Gdn::cache()->get($cacheKey);
        if ($posts !== Gdn_Cache::CACHEOP_FAILURE) {
            return $posts;
        }

        // Filter permissions.
        $wheres = ['UserID' => $userID];
        $perms = DiscussionModel::categoryPermissions();
        if (is_array($perms)) {
            $wheres['CategoryID'] = $perms;
        }

        // Retrieve data.
        $mentioned = $this->getWhere(
            $wheres,
            'DateInserted',
            'desc',
            $limit,
            $offset
        )->resultArray();

        // Get IDs of discssions and comments.
        $postIDs = array_reduce(
            $mentioned,
            function ($postIDs, $post) {
                $postIDs[$post['ForeignType']][] = $post["{$post['ForeignType']}ID"];
                return $postIDs;
            }
        );

        // Load posts from db.
        if (count($postIDs['Discussion']) > 0) {
            $discussions = Gdn::sql()
                ->select('*')
                ->select('"Discussion"', '', 'ForeignType')
                ->from('Discussion')
                ->where(['DiscussionID' => $postIDs['Discussion']])
                ->get()
                ->resultObject();
        } else {
            $discussions = [];
        }

        if (count($postIDs['Comment']) > 0) {
            $comments = Gdn::sql()
                ->select('c.*, d.Name, d.CategoryID')
                ->select('"Comment"', '', 'ForeignType')
                ->from('Comment c')
                ->join('Discussion d', 'c.DiscussionID = d.DiscussionID', 'right')
                ->where(['CommentID' => $postIDs['Comment']])
                ->get()
                ->resultObject();
            } else {
                $comments = [];
            }

        // Consolidate discussions and comments and sort them.
        $posts = array_merge($discussions, $comments);
        usort(
            $posts,
            function ($a, $b) {
                if ($a->DateInserted < $b->DateInserted) {
                    return 1; // return $a;
                } else {
                    return -1; // return $b;
                }
            }
        );

        // Store the values in cache for later access
        Gdn::cache()->store(
            $cacheKey,
            $posts,
            [Gdn_Cache::FEATURE_EXPIRY => 120] // unit is seconds
        );

        return $posts;
    }

    public function deleteID($commentID, $options = []) {
        // Delete comment from Mentioned table.
        $this->SQL->delete('Mentioned', ['CommentID' => $commentID]);

        // Get users mentioned in comment.
        $mentionedUserNames = getMentions($options['Comment']->Body);
        foreach ($mentionedUserNames as $userName) {
            $user = Gdn::userModel()->getByUsername($userName);
            // Update CountMentioned for each mentioned user.
            Gdn::userModel()->setField(
                $user->UserID,
                'CountMentioned',
                $this->getCount(['UserID' => $user->UserID])
            );
        }
    }
}
