<?php

$PluginInfo['mentioned'] = [
    'Name' => 'Mentioned',
    'Description' => 'Adds a list of discussions in that a user has been mentioned to the users profile.',
    'Version' => '0.1.0',
    'RequiredApplications' => ['Vanilla' => '>= 2.6'],
    'RequiredPlugins' => false,
    'RequiredTheme' => false,
    'SettingsPermission' => 'Garden.Settings.Manage',
    'SettingsUrl' => '/settings/mentioned', // ToDo: implement a "Recalc now" button
    'MobileFriendly' => true,
    'HasLocale' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'https://open.vanillaforums.com/profile/r_j',
    'License' => 'MIT'
];

class MentionedPlugin extends Gdn_Plugin {
    /**
     * Initiate db changes.
     *
     * @return void.
     */
    public function setup() {
        $this->structure();
    }

    /**
     * Change db structure.
     *
     * @return void.
     */
    public function structure() {
        // Add new table.
        $database = Gdn::database();
        $database->structure()
            ->table('Mentioned')
            ->column('ForeignType', ['Discussion', 'Comment'], false)
            ->column('ForeignID', 'int(11)', false)
            ->column('UserID', 'int(11)', false, 'index')
            ->column('InsertUserID', 'int(11)', false)
            ->column('DateInserted', 'datetime')
            ->set();

        // Construct key to ensure unique entries.
        $px = $database->DatabasePrefix;
        $sql = "ALTER TABLE {$px}Mentioned";
        $sql .= ' ADD UNIQUE KEY `UX_UserID_ForeignType_ForeignID`';
        $sql .= ' (`UserID`, `ForeignType`, `ForeignID`)';
        try {
            $database->sql()->query($sql);
        } catch (Exception $e) {
        }

        // Add column to profile.
        $database->structure()
            ->table('User')
            ->column('CountMentioned', 'int(11)', 0)
            ->set();
    }

    /**
     * Save users that are mentioned in a discussion.
     *
     * @param discussionModel $sender Sending controller instance.
     * @param array $args Event arguments.
     *
     * @return void.
     */
    public function discussionModel_beforeNotification_handler($sender, $args) {
        $this->addMentioned(
            'Discussion',
            $args['Discussion']['DiscussionID'],
            $args['Discussion']['InsertUserID'],
            $args['MentionedUsers'],
            $args['Discussion']['DateInserted']
        );
    }

    /**
     * Save users that are mentioned in a comment.
     *
     * @param commentModel $sender Sending controller instance.
     * @param array $args Event arguments.
     *
     * @return void.
     */
    public function commentModel_beforeNotification_handler($sender, $args) {
        $this->addMentioned(
            'Comment',
            $args['Comment']['CommentID'],
            $args['Comment']['InsertUserID'],
            $args['MentionedUsers'],
            $args['Comment']['DateInserted']
        );
    }

    /**
     * Add mentioned information to db.
     *
     * @param string $foreignType        One of Discussion/Comment
     * @param int    $foreignID          The ID of the Discussion/Comment
     * @param int    $insertUserID       The ID of the user who mentioned another user
     * @param array  $mentionedUserNames The names that are mentioned
     * @param string $dateInserted       Timestamp of the mentioning
     *
     * @return  void
     */
    protected function addMentioned(
        string $foreignType,
        int $foreignID,
        int $insertUserID,
        $mentionedUserNames,
        string $dateInserted = ''
    ) {
        if (!is_array($mentionedUserNames)) {
            $mentionedUserNames = (array)$mentionedUserNames;
        }
        if (count($mentionedUserNames) == 0) {
            return;
        }
        if ($dateinserted == '') {
            $dateInserted = Gdn_Format::toDateTime();
        }
        $userModel = Gdn::userModel();
        $database = Gdn::database();

        // Loop through all users.
        foreach ($mentionedUserNames as $userName) {
            $user = $userModel->getByUsername($userName);
            $database->sql()
                ->options('Ignore', true)
                ->insert(
                    'Mentioned',
                    [
                        'ForeignType' => $foreignType,
                        'ForeignID' => $foreignID,
                        'UserID' => $user->UserID,
                        'InsertUserID' => $insertUserID,
                        'DateInserted' => $dateInserted
                    ]
                );
            if ($database->LastInfo['RowCount'] > 0) {
                $database->sql()
                    ->update('User')
                    ->set('CountMentioned', 'CountMentioned + 1', false)
                    ->where('UserID', $user->UserID)
                    ->put();
            }
        }
    }

    /**
     * Clean up if discussion is deleted.
     *
     * @param discussionModel $sender Sending controller instance.
     * @param array           $args   Event arguments.
     *
     * @return void.
     */
    public function discussionModel_deleteDiscussion_handler($sender, $args) {
        $this->deleteMentioned('Discussion', $args['DiscussionID']);
    }

    /**
     * Clean up if comment is deleted.
     *
     * @param commentModel $sender Sending controller instance.
     * @param array        $args   Event arguments.
     *
     * @return void.
     */
    public function commentModel_deleteComment_handler($sender, $args) {
        $this->deleteMentioned('Comment', $args['CommentID']);
    }

    /**
     * Clear the mentioned table from post related entries.
     *
     * @param string $foreignType One of Discussion/Comment
     * @param int    $foreignID   The ID of the Discussion/Comment
     *
     * @return void.
     */
    protected function deleteMentioned(string $foreignType, int $foreignID) {
        // Keep UserIDs for refreshing their count.
        $userIDs = Gdn::sql()
            ->select('UserID')
            ->from('Mentioned')
            ->where(
                [
                    'ForeignType' => $foreignType,
                    'ForeignID' => $foreignID
                ]
            )
            ->get()
            ->resultArray();

        // Remove rows.
        Gdn::sql()->delete(
            'Mentioned',
            ['ForeignType' => $foreignType, 'ForeignID' => $foreignID]
        );

        $this->refreshCount(array_column($userIDs, 'UserID'));
    }

    /**
     * Refresh the CountMentioned field in user table.
     *
     * @param array $userIDs Users to be refreshed.
     *
     * @return void
     */
    protected function refreshCount($userIDs = []) {
        if (count($userIDs) == 0) {
            return;
        }

        // Build and run count query.
        $px = Gdn::database()->DatabasePrefix;
        $sql = "UPDATE {$px}User u";
        $sql .= ' SET u.CountMentioned = (';
        $sql .= '   SELECT COUNT(m.UserID)';
        $sql .= "   FROM {$px}Mentioned m";
        $sql .= '   WHERE u.UserID = m.UserID';
        $sql .= ' )';
        $sql .= " WHERE u.UserID in ('";
        $sql .= implode("','", $userIDs);
        $sql .= "')";
        Gdn::sql()->query($sql);
    }

    /**
     * Add link to new profile page.
     *
     * @param profileController $sender Sending controller instance.
     *
     * @return void.
     */
    public function profileController_addProfileTabs_handler($sender) {



            // Add the article tab

        if (is_object($sender->User) && $sender->User->CountMentioned > 0 && $sender->User->UserID > 0) {
            $mentionedLabel = sprite('SpMentioned').' '.t('Mentioned');
            if (c('mentioned.Profile.ShowCounts', true)) {
                $mentionedLabel .= '<span class="Aside">'.countString(
                    $sender->User->CountMentioned,
                    "/profile/count/mentioned?userid={$sender->User->UserID}"
                ).'</span>';
            }
            $sender->addProfileTab(
                t('Mentioned'),
                userUrl($sender->User, '', 'mentioned'),
                'MentionedTab',
                $mentionedLabel
            );
        }
    }

    /**
     * Build "Mentioned" view in profile.
     *
     * Add new view in profile showing all comments and discussions where a
     * user has been mentioned.
     *
     * @param profileController $sender Sending controller instance.
     * @param array             $args   Event arguments.
     *
     * @return void.
     */
    public function profileController_mentioned_create($sender, $args) {
        $sender->editMode(false);

        // If search engines wouldn't be excluded, this page would create
        // duplicate and "incomplete" content.
        if ($sender->Head) {
            $sender->Head->addTag(
                'meta',
                [
                    'name' => 'robots',
                    'content' => 'noindex,noarchive'
                ]
            );
        }

        // Set the current profile user.
        if (!isset($args[0])) {
            $sender->getUserInfo('', '', Gdn::session()->UserID, false);
        } else {
            $sender->getUserInfo($args[0], '', '', false);
        }
        $userID = $sender->User->UserID;



        $totalRecords = $sender->User->CountMentioned;
        // "Fake" data if most probably there is no data in the db, do all the
        // heavy work otherwise.
        if (!$totalRecords) {
            $mentioned = (object)[];
        } else {
            // Prepare pager.
            $pageSize = c('Vanilla.Discussions.PerPage', 30);
            if (isset($args[2])) {
                $page = $args[2];
                list($offset, $limit) = offsetLimit($page, $pageSize);
            } else {
                $page = '';
                $offset = 0;
                $limit = $pageSize;
            }

            // Build a pager
            $pagerFactory = new Gdn_PagerFactory();
            $sender->Pager = $pagerFactory->getPager('MorePager', $sender);
            $sender->Pager->MoreCode = 'More Posts';
            $sender->Pager->LessCode = 'Newer Posts';
            $sender->Pager->ClientID = 'Pager';
            $sender->Pager->configure(
                $offset,
                $limit,
                $totalRecords,
                userUrl($sender->User, '', 'mentioned').'?page={Page}'
            );

            // Deliver JSON data if necessary
            if ($sender->deliveryType() != DELIVERY_TYPE_ALL && $offset > 0) {
                $sender->setJson('LessRow', $sender->Pager->toString('less'));
                $sender->setJson('MoreRow', $sender->Pager->toString('more'));
                $sender->View = 'mentioned';
            }

            $wheres = ['m.UserID' => $userID];
            $perms = DiscussionModel::categoryPermissions();
            if (is_array($perms)) {
                $wheres['d.CategoryID'] = $perms;
            }

            // Needs two queries to get Comments and Discussions
            $query = Gdn::sql()
                ->select('m.ForeignID AS PrimaryID, m.ForeignType AS RecordType')
                ->select('d.Name AS Title, d.Body AS Summary, d.Format, d.CategoryID')
                ->select('d.InsertUserID AS UserID, d.DateInserted')
                ->from('Mentioned m')
                ->join(
                    'Discussion d',
                    "m.ForeignType = 'Discussion' and m.ForeignID = d.DiscussionID",
                    'left'
                )
                ->where($wheres)
            ->getSelect();
            $discussionQuery = Gdn::sql()->applyParameters($query);
            Gdn::sql()->reset();

            $query = Gdn::sql()
                ->select('m.ForeignID AS PrimaryID, m.ForeignType AS RecordType')
                ->select('d.Name AS Title, c.Body AS Summary, c.Format, d.CategoryID')
                ->select('c.InsertUserID AS UserID, c.DateInserted')
                ->from('Mentioned m')
                ->join(
                    'Comment c',
                    "m.ForeignType = 'Comment' and m.ForeignID = c.CommentID",
                    'left'
                )
                ->join(
                    'Discussion d',
                    'c.DiscussionID = d.DiscussionID',
                    'left'
                )
                ->where($wheres)
            ->getSelect();
            $commentQuery = Gdn::sql()->applyParameters($query);
            Gdn::sql()->reset();

            $mentionedQuery = "$discussionQuery\nUNION\n$commentQuery\nORDER BY DateInserted";
            if ($limit) {
                $mentionedQuery .= "\nLIMIT $limit";
            }
            if ($offset) {
                $mentionedQuery .= "\nOFFSET $offset";
            }
            $mentioned = Gdn::sql()->query($mentionedQuery)->resultArray();
            Gdn::userModel()->joinUsers($mentioned, ['UserID']);
            foreach ($mentioned as $key => $row) {
                // Create the summary.
                $mentioned[$key]['Summary'] = condense(
                    Gdn_Format::to($row['Summary'], $row['Format'])
                );

                $row['Summary'] = searchExcerpt(
                    htmlspecialchars(
                        Gdn_Format::plainText($row['Summary'], $row['Format'])
                    ),
                    '@'.$sender->User->Name
                );
                $mentioned[$key]['Summary'] = Emoji::instance()->translateToHtml($row['Summary']);
                $mentioned[$key]['Format'] = 'Html';

                // Add the post url.
                if ($row['RecordType'] == 'Comment') {
                    $url = commentUrl(['CommentID' => $row['PrimaryID']]);
                } else {
                    $url = discussionUrl([
                        'DiscussionID' => $row['PrimaryID'],
                        'Name' =>  $row['Title']
                    ]);
                }
                $mentioned[$key]['Url'] = $url;
            }
        }

        // Initiate view informations.
        $sender->_setBreadcrumbs(t('Mentioned'), userUrl($sender->User, '', 'Mentioned'));
        $sender->setData('SearchResults', $mentioned);
        $sender->setData('RecordCount', $totalRecords);
        $sender->setData('TabTitle', t('Mentioned'));

        $sender->setTabView(
            t('Mentioned'),
            $sender->fetchViewLocation('results', 'search', 'dashboard')
        );

        $sender->render();
    }
}
