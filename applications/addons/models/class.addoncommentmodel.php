<?php
/**
 *
 *
 * @copyright 2009-2015 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package Addons
 * @since 2.0
 */

/**
 * Class AddonCommentModel
 */
class AddonCommentModel extends Gdn_Model {

    /**
     * AddonCommentModel constructor.
     */
    public function __construct() {
        parent::__construct('AddonComment');
    }

    /**
     *
     */
    public function AddonCommentQuery() {
        $this->SQL->Select('c.*')
            ->Select('iu.Name', '', 'InsertName')
            ->Select('iu.Photo', '', 'InsertPhoto')
            ->From('AddonComment c')
            ->Join('User iu', 'c.InsertUserID = iu.UserID', 'left');
    }

    /**
     *
     *
     * @param string $AddonID
     * @param string $Limit
     * @param int $Offset
     * @return Gdn_DataSet
     * @throws Exception
     */
    public function Get($AddonID, $Limit, $Offset = 0) {
        $this->AddonCommentQuery();
        $this->FireEvent('BeforeGet');
        return $this->SQL
            ->Where('c.AddonID', $AddonID)
            ->OrderBy('c.DateInserted', 'asc')
            ->Limit($Limit, $Offset)
            ->Get();
    }

    /**
     *
     *
     * @param mixed $AddonCommentID
     * @return array|bool|stdClass
     */
    public function GetID($AddonCommentID) {
        $this->CommentQuery();
        return $this->SQL
            ->Where('c.AddonCommentID', $AddonCommentID)
            ->Get()
            ->FirstRow();
    }

    /**
     *
     *
     * @param $AddonID
     * @param $LastCommentID
     * @return Gdn_DataSet
     */
    public function GetNew($AddonID, $LastCommentID) {
        $this->CommentQuery();
        return $this->SQL
            ->Where('c.AddonID', $AddonID)
            ->Where('c.AddonCommentID >', $LastCommentID)
            ->OrderBy('c.DateInserted', 'asc')
            ->Get();
    }

    /**
     * Returns the offset of the specified comment in it's related discussion.
     *
     * @param int $AddonCommentID
     * @return mixed
     */
    public function GetOffset($AddonCommentID) {
        return $this->SQL
            ->Select('c2.AddonCommentID', 'count', 'CountComments')
            ->From('AddonComment c')
            ->Join('Addon a', 'c.AddonID = a.AddonID')
            ->Join('AddonComment c2', 'a.AddonID = c2.AddonID')
            ->Where('c2.AddonCommentID <=', $AddonCommentID)
            ->Where('c.AddonCommentID', $AddonCommentID)
            ->Get()
            ->FirstRow()
            ->CountComments;
    }

    /**
     *
     *
     * @param array $FormPostValues
     * @return bool|Gdn_DataSet|int|mixed|object|string
     */
    public function Save($FormPostValues) {
        $Session = Gdn::Session();

        // Define the primary key in this model's table.
        $this->DefineSchema();

        // Add & apply any extra validation rules:
        $this->Validation->ApplyRule('Body', 'Required');
        $MaxCommentLength = Gdn::Config('Vanilla.Comment.MaxLength');
        if (is_numeric($MaxCommentLength) && $MaxCommentLength > 0) {
            $this->Validation->SetSchemaProperty('Body', 'Length', $MaxCommentLength);
            $this->Validation->ApplyRule('Body', 'Length');
        }

        $AddonCommentID = ArrayValue('AddonCommentID', $FormPostValues);
        $AddonCommentID = is_numeric($AddonCommentID) && $AddonCommentID > 0 ? $AddonCommentID : false;
        $Insert = $AddonCommentID === false;
        if ($Insert) {
            $this->AddInsertFields($FormPostValues);
        } else {
            $this->AddUpdateFields($FormPostValues);
        }

        // Validate the form posted values
        if ($this->Validate($FormPostValues, $Insert)) {
            // If the post is new
            $Fields = $this->Validation->SchemaValidationFields();
            $Fields = RemoveKeyFromArray($Fields, $this->PrimaryKey);
            $AddonID = ArrayValue('AddonID', $Fields);
            if ($Insert === false) {
                $this->SQL->Put($this->Name, $Fields, array('AddonCommentID' => $AddonCommentID));
            } else {
                // Make sure that the comments get formatted in the method defined by Garden
                $Fields['Format'] = Gdn::Config('Garden.InputFormatter', '');
                $AddonCommentID = $this->SQL->Insert($this->Name, $Fields);

                // Notify any users who were mentioned in the comment
                $Usernames = GetMentions($Fields['Body']);
                $UserModel = Gdn::UserModel();
                foreach ($Usernames as $Username) {
                    $User = $UserModel->GetByUsername($Username);
                    if ($User && $User->UserID != $Session->UserID) {
                        AddActivity(
                            $Session->UserID,
                            'AddonCommentMention',
                            '',
                            $User->UserID,
                            'addon/'.$AddonID.'/#Comment_'.$AddonCommentID
                        );
                    }
                }
            }
            // Record user-comment activity
            if ($AddonID !== false) {
                $this->RecordActivity($AddonID, $Session->UserID, $AddonCommentID);
            }
        }
        return $AddonCommentID;
    }

    /**
     *
     *
     * @param $AddonID
     * @param $ActivityUserID
     * @param $AddonCommentID
     */
    public function RecordActivity($AddonID, $ActivityUserID, $AddonCommentID) {
        // Get the author of the discussion
        $AddonModel = new AddonModel();
        $Addon = $AddonModel->GetID($AddonID);
        if ($Addon->InsertUserID != $ActivityUserID) {
            AddActivity(
                $ActivityUserID,
                'AddonComment',
                '',
                $Addon->InsertUserID,
                'addon/'.$AddonID.'/'.Gdn_Format::Url($Addon->Name).'/#Comment_'.$AddonCommentID
            );
        }
    }

    /**
     *
     *
     * @param string|unknown_type $AddonCommentID
     * @return bool
     */
    public function Delete($AddonCommentID) {
        $this->SQL->Delete('AddonComment', array('AddonCommentID' => $AddonCommentID));
        return true;
    }
}
