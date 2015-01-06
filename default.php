<?php if (!defined('APPLICATION')) exit();
// Define the plugin:
$PluginInfo['PurchaseInvites'] = array(
   'Name' => 'Purchase Invites',
   'Description' => "Allows users to purchace invites",
   'Version' => '0.1.3b',
   'RequiredPlugins' => array('MarketPlace' => '0.1.9b'),
   'RequiredApplications' => array('Vanilla' => '2.1'),
   'Author' => 'Paul Thomas',
   'AuthorEmail' => 'dt01pqt_pt@yahoo.com',
   'AuthorUrl' => 'http://www.vanillaforums.org/profile/x00'
);

class PurchaseInvites extends Gdn_Plugin {
    
    public static function PreConditions($UserID,$Product){
        $User =  Gdn::UserModel()->GetID($UserID);
        if ($User->Admin)
            return array('status'=>'error','errormsg'=>T('You have unlimited invites, so don\'t need to purchase them.'));
        else
            return array('status'=>'pass');
    }
    
    public static function AddInvites($UserID,$Product,$TransactionID){
        $Quantity=1;
        $VariableMeta=MarketTransaction::GetTransactionMeta($TransactionID);
        $Meta=Gdn_Format::Unserialize($Product->Meta);
        $DefaultQuantity = GetValue('Quantity',$Meta,1);
        $DefaultQuantity = ctype_digit($DefaultQuantity)?$DefaultQuantity:1;
        $Quantity=GetValue('Quantity',$VariableMeta,$DefaultQuantity);
        $User =  Gdn::UserModel()->GetID($UserID);
        $CurrentCount = $User->CountInvitations;
        Gdn::SQL()
        ->Put(
            'User',
            array(
                'CountInvitations'=>$CurrentCount+$Quantity,
                'DateSetInvitations'=>Gdn_Format::ToDateTime(strtotime(C('Plugin.PurchaseInvites.Expire','+1 months')))
            ),
            array(
                'UserID'=>$UserID
            )
        );
        $Invites = UserModel::GetMeta($User->UserID,'Invites.%','Invites.');
        UserModel::SetMeta($UserID,array('Purchased'=>GetValue('Purchased',$Invites,0)+$Quantity,'Total'=>$CurrentCount+$Quantity),'Invites.');
        
        return array('status'=>'success');
        
    }
    
    public function MarketPlace_LoadMarketPlace_Handler($Sender){
        $Options = array(
            'Meta'=>array('Quantity'),
            'RequiredMeta'=>array('Quantity'),
            'ValidateMeta'=>array('Quantity'=>'Integer'),
            'VariableMeta'=>array('Quantity'),
            'ReturnComplete'=>'/profile/invitations'
        );
        $Sender->RegisterProductType('PurchaseInvites','Allows users to purchase invites',$Options,'PurchaseInvites::PreConditions','PurchaseInvites::AddInvites');
    }
    
    public function ProfileController_Render_Before($Sender){
        if(stripos($Sender->RequestMethod,'invit')!==FALSE && strtolower($Sender->RequestMethod)!='uninvite'){
            $Invites = UserModel::GetMeta(Gdn::Session()->UserID,'Invites.%','Invites.');
            $Purchased = GetValue('Purchased',$Invites,0);
            $Total = GetValue('Total',$Invites,0);
            $User =  Gdn::UserModel()->GetID(Gdn::Session()->UserID);
            if($Total!=$User->CountInvitations){
                $Purchased+=$User->CountInvitations-$Total;
                $Purchased=$Purchased>0?$Purchased:0;
                $Total=$User->CountInvitations;
                UserModel::SetMeta(Gdn::Session()->UserID,array('Purchased'=>$Purchased,'Total'=>$Total),'Invites.');
            }
            $Sender->InvitationCount=$Total;
            
            $Message='';
            if ($Sender->Form->AuthenticatedPostBack() && !$Sender->UserModel->GetInvitationCount(Gdn::Session()->UserID)) {
                $InvitationModel = new InvitationModel();
                $Sender->Form->SetModel($InvitationModel);
                $FormValues = $Sender->Form->FormValues();
                if ($InvitationModel->Save2($FormValues,$Sender->UserModel,$Total)) {
                    $Sender->InformMessage(T('Your invitation has been sent.'));
                    $Sender->Form = Gdn::Factory('Form'); //clear to pass
                }
                $Sender->Form->SetValidationResults($InvitationModel->Validation->Results());
            }
            if($Purchased){
                $Message = sprintf(T('You have purchased %s invitations, and have %s left in total. You need to use them up by %s, or they will expire.'),$Purchased,$Total,Gdn_Format::Date($User->DateSetInvitations));
            }else{
                $Message = sprintf(T('You %s invitations. You need to use them up by %s, or they will expire.'),$Total,Gdn_Format::Date($User->DateSetInvitations));
            }
            
            if($Message)
                Gdn::Locale()->SetTranslation('You have %s invitations left for this month.',$Message);
        }
    }
    
    public function InvitationModel_Save2_Create($Sender){//mod of invitationmodel Save forcing invitation count
        list($FormPostValues,$UserModel,$InviteCount) = $Sender->EventArguments;
        $Session = Gdn::Session();
        $UserID = $Session->UserID;
        $Sender->Validation = new Gdn_Validation();

        // Define the primary key in this model's table.
        $Sender->DefineSchema();

        // Add & apply any extra validation rules:      
        $Sender->Validation->ApplyRule('Email', 'Email');


        
        if (!isset($FormPostValues[$Sender->DateInserted]))
            $FormPostValues[$Sender->DateInserted] = Gdn_Format::ToDateTime();


        if ($Session->UserID > 0)
            if (!isset($FormPostValuess[$Sender->InsertUserID]))
                $FormPostValues[$Sender->InsertUserID] = $Session->UserID;

        if (!isset($FormPostValues['InsertIPAddress'])) {
            $FormPostValues['InsertIPAddress'] = Gdn::Request()->IpAddress();
        }
            
        $FormPostValues['Code'] = $Sender->GetInvitationCode2();
        // Validate the form posted values
        if ($Sender->Validation->Validate($FormPostValues, TRUE) === TRUE) {
        
         
            $Fields = $Sender->Validation->ValidationFields(); // All fields on the form that need to be validated
            $Email = ArrayValue('Email', $Fields, '');
         
         // Make sure this user has a spare invitation to send.
         //$InviteCount = $UserModel->GetInvitationCount($UserID);

         if ($InviteCount == 0) {
            $Sender->Validation->AddValidationResult('Email', 'You do not have enough invitations left.');
            return FALSE;
         }
         
         // Make sure that the email does not already belong to an account in the application.
         $TestData = $UserModel->GetWhere(array('Email' => $Email));
         if ($TestData->NumRows() > 0) {
            $Sender->Validation->AddValidationResult('Email', 'The email you have entered is already related to an existing account.');
            return FALSE;
         }
         
         // Make sure that the email does not already belong to an invitation in the application.
         $TestData = $Sender->GetWhere(array('Email' => $Email));
         if ($TestData->NumRows() > 0) {
            $Sender->Validation->AddValidationResult('Email', 'An invitation has already been sent to the email you entered.');
            return FALSE;
         }
            
         // Define the fields to be inserted
         $Fields = $Sender->Validation->SchemaValidationFields();
         
         // Call the base model for saving
         $InvitationID = $Sender->Insert($Fields);
         
         // Now that saving has succeeded, update the user's invitation settings
         if ($InviteCount > 0){
              if ($InviteCount != -1){

              $Sender->SQL->Update('User')
                 ->Set('CountInvitations', 'CountInvitations - 1', FALSE)
                 ->Where('UserID', $UserID)
                 ->Put();
            }
            
        }
         
         // And send the invitation email
         try {
            $Sender->Send($InvitationID);
         } catch (Exception $ex) {
            $Sender->Validation->AddValidationResult('Email', sprintf(T('Although the invitation was created successfully, the email failed to send. The server reported the following error: %s'), strip_tags($ex->getMessage())));
            return FALSE;
         }
         return TRUE;
        }
        return FALSE;
    }
    
    public function InvitationModel_GetInvitationCode2_Create($Sender){
        // Generate a new invitation code.
        $Code = RandomString(8);

        // Make sure the string doesn't already exist in the invitation table
        $CodeData = $Sender->GetWhere(array('Code' => $Code));
        if ($CodeData->NumRows() > 0) {
         return $Sender->GetInvitationCode();
        } else {
         return $Code;         
        }
    }
    
    public function Setup() {

        $this->Structure();
    }
    
    public function Structure(){
        
    }
    
    
    

}
