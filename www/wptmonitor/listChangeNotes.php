<?php
require("login/login.php");
include 'monitor.inc';
include 'db_utils.inc';
$user_id = getCurrentUserId();

if (($showPublic = $_REQUEST['showPublic'])) {
  $_SESSION['changeNoteShowPublic'] = $showPublic;
}
if (!$_SESSION['changeNoteShowPublic']) {
  $_SESSION['changeNoteShowPublic'] = 'false';
}
$smarty->assign('showPublic', $_SESSION['changeNoteShowPublic']);
// Folder
if (($folderId = $_REQUEST['folderId'])) {
  $_SESSION['changeNoteFolderId'] = $folderId;
}
if (!$_SESSION['changeNoteFolderId']) {
  $_SESSION['changeNoteFolderId'] = getRootFolderForUser($user_id, 'ChangeNote');;
}

$folderId = $_SESSION['changeNoteFolderId'];

$smarty->assign('folderId', $_SESSION['changeNoteFolderId']);

$folderTree = getFolderTree($user_id, 'ChangeNote');
$smarty->assign('folderTree', $folderTree);

try
{
  $q = Doctrine_Query::create()->from('ChangeNote c, c.ChangeNoteFolder f')
      ->groupBy('c.Id');

  if ($folderId > -1 && hasPermission('ChangeNote',$folderId,PERMISSION_READ)) {
    $q->andWhere('c.ChangeNoteFolderId = ?', $folderId);
  } else {
    $q->andWhere('c.UserId = ?', $user_id)->orWhere('c.Public = ?', true);
  }
  $pager = new Doctrine_Pager($q, $changeNotesCurrentPage, $resultsPerPage);
  $result = $pager->execute();

  //  $changeNoteTable = Doctrine_Core::getTable('ChangeNote');
  //  $changeNotes = $changeNoteTable->findAll();
  $smarty->assign('result', $result);
}
catch (Exception $e)
{
  error_log("[WPTMonitor] Failed while Listing Change Notes: " . $e->getMessage());
}
unset($changeNotes);
$smarty->assign('userId', getCurrentUserId());

$shares = getFolderShares($user_id, 'ChangeNote');
$smarty->assign('shares', $shares);

$smarty->display('changenote/listChangeNotes.tpl');
?>
 
