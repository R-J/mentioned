<?php

$PluginInfo['mentioned'] = [
    'Name' => 'Mentioned',
    'Description' => 'Adds a list of discussions in that a user has been mentioned to the users profile.',
    'Version' => '0.1',
    'RequiredApplications' => ['Vanilla' => '>= 2.2'],
    'RequiredPlugins' => false,
    'RequiredTheme' => false,
    'SettingsPermission' => 'Garden.Settings.Manage',
    'SettingsUrl' => '/settings/mentioned',
    'MobileFriendly' => true,
    'HasLocale' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/44046/R_J',
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
        Gdn::structure()
            ->table('Mentioned')
            ->primaryKey('MentionedID')
            ->column('UserID', 'int(11)', false, 'index')
            ->column('ForeignType', ['Discussion', 'Comment'], false)
            ->column('ForeignID', 'int(11)', false)
            ->set();

        // Add column to profile.
        Gdn::structure()
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
            'Comment',
            $args['Comment']['CommentID'],
            $args['MentionedUsers']
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
            $args['MentionedUsers']
        );
    }

    /**
     * Add entry to Mentioned table and increase count in User table.
     *
     * @param [type] $foreignType [description]
     * @param [type] $foreignID   [description]
     * @param [type] $userNames   [description]
     */
    protected function addMentioned($foreignType, $foreignID, $userNames) {
        $userModel = Gdn::UserModel();
        if (!is_array($userNames)) {
            $userNames = (array)$userNames;
        }
        // Loop through all users.
        foreach ($userNames as $userName) {
            $user = $userModel->getByUsername($userName);

            // Check if line already exists.
            $count = Gdn::sql()->getCount(
                'Mentioned',
                [
                    'ForeignType' => $foreignType,
                    'ForeignID' => $foreignID,
                    'UserID' => $user->UserID
                ]
            );

            // If not, insert line and update count.
            if ($count == 0) {
                Gdn::sql()->insert(
                    'Mentioned',
                    [
                        'ForeignType' => $foreignType,
                        'ForeignID' => $foreignID,
                        'UserID' => $user->UserID
                    ]
                );
                Gdn::sql()
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
     * @param array $args Event arguments.
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
     * @param array $args Event arguments.
     *
     * @return void.
     */
    public function commentModel_deleteComment_handler($sender, $args) {
        $this->deleteMentioned('Comment', $args['Comment']);
    }

    protected function deleteMentioned($foreignType, $foreignID) {
        // Keep UserIDs for refreshing their count.
        $userIDs = Gdn::sql()->getWhere(
            'Mentioned',
            [
                'ForeignType' => $foreignType,
                'ForeignID' => $foreignID
            ]
        )->resultArray();

        // Remove rows.
        Gdn::sql()->delete(
            'Mentioned',
            ['ForeignType' => $foreignType, 'ForeignID' => $foreignID]
        );

        $this->refreshCount($userIDs);
    }

    protected function refreshCount($userIDs = []) {
        $px = Gdn::database()->DatabasePrefix;
        $sql = "UPDATE {$px}User u";
        $sql .= ' SET u.CountMentioned = (';
        $sql .= '   SELECT COUNT(m.UserID)';
        $sql .= "   FROM {$px}Mentioned m";
        $sql .= '   WHERE u.UserID = m.UserID';
        $sql .= ' )';
        if (count($userIDs) > 0) {
            $sql .= " WHERE u.UserID in ('";
            $sql .= implode("','", $userIDs);
            $sql .= "')";
        }
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
        if (is_object($sender->User) && $sender->User->UserID > 0) {
            $sender->addProfileTab(
                t('Mentioned'),
                userUrl($sender->User, '', 'mentioned'),
                'MentionedTab',
                '<span class="Sprite SpMentioned"></span> '.t('Mentioned')
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
     * @param array $args Event arguments.
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
            $userID = Gdn::session()->UserID;
        } else {
            $userID = intval($args[0]);
        }
        $sender->getUserInfo('', '', $userID, false);

        // Prepare pager.
        $pageSize = Gdn::config('Vanilla.Discussions.PerPage', 30);
        if (isset($args[2])) {
            $page = $args[2];
            list($offset, $limit) = offsetLimit($page, $pageSize);
        } else {
            $page = '';
            $offset = 0;
            $limit = $pageSize;
        }

        $mentionedModel = new MentionedModel();
        $wheres = ['UserID' => $userID];
        $perms = DiscussionModel::categoryPermissions();
        if (is_array($perms)) {
            $wheres['CategoryID'] = $perms;
        }
        $totalRecords = $mentionedModel->getCount($wheres);

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

        $mentioned = $mentionedModel->getByUserID(
            $userID,
            $limit,
            $offset
        );

        // Initiate view informations.
        $sender->_setBreadcrumbs(t('Mentioned'), userUrl($sender->User, '', 'Mentioned'));
        $sender->setData('Mentioned', $mentioned);
        $sender->setData('TabTitle', t('Mentioned'));

        $sender->setTabView(
            t('Mentioned'),
            $this->getView('profile.php')
        );

        $sender->render();
    }
}