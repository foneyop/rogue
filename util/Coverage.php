<?php
/**
 * Audit code coverage.   Logs every time a block of code is hit
 *
 * @author cory
 */
class Coverage
{

	public static function addBlocks($className, array $ids)
	{
		if (!PROFILE)
			return;
		$db = DB::getConnection('Profile', true);
		foreach ($ids as $id) {
			try {
				$db->insert('add-blocks', 'AuditPoint', array('id' => $id, 'class' => $className));
				//$db->sqlDelayedStmt('add-blocks', "INSERT INTO AuditPoint values(?, ?)", array($id, $className));
			}
			catch (DuplicateKeyException $e) { // normal
			}
		}
	}

	public static function audit($id, $value = '')
	{
		if (!PROFILE)
			return;
		$db = DB::getConnection('Profile', true);
		$uid = isset($GLOBALS['cuserid']) ? $GLOBALS['cuserid'] : null;
		if (isset($_COOKIE['sid'])) {
			$sid = $_COOKIE['sid'];
		}
		else {
			mt_srand();
			$sid = mt_rand(1, 400000000);
			$_COOKIE['sid'] = $sid;
			setcookie('sid', $sid, time()+3600);
		}
		$db->insert('audit', 'Audit', array('id' => $id, 'userId' => $uid, 'created' => null, 'value' => $value, 'sessionId' => $sid));
		//$db->sqlDelayedStmt('audit-code', 'INSERT INTO Audit values (?, ?, ?, ?, ?)', array($id, $uid, null, $value, $sid));
	}

}
?>
