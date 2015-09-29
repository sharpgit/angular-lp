<?php 
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(-1);

// This is an example of how to use the LiquidPlanner API in PHP.
class LiquidPlanner {
  private $_base_uri = "https://app.liquidplanner.com/api";
  private $_ch;
  public  $workspace_id;
  function __construct($email, $password) {
    $this->_ch = curl_init();
    curl_setopt($this->_ch, CURLOPT_HEADER, false);
    curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->_ch, CURLOPT_USERPWD, "$email:$password");
    curl_setopt($this->_ch, CURLOPT_HTTPHEADER, array('content-type: application/json'));
    curl_setopt($this->_ch, CURLOPT_ENCODING, 'gzip');
  }
  public function get($url) {
    curl_setopt($this->_ch, CURLOPT_HTTPGET, true);
    curl_setopt($this->_ch, CURLOPT_URL, $this->_base_uri.$url);
    return json_decode(curl_exec($this->_ch));
  }
  public function post($url, $body=null) {
    curl_setopt($this->_ch, CURLOPT_POST, true);
    curl_setopt($this->_ch, CURLOPT_URL, $this->_base_uri.$url);
    curl_setopt($this->_ch, CURLOPT_POSTFIELDS, json_encode($body));
    return json_decode(curl_exec($this->_ch));
  }
  public function put($url, $body=null) {
    curl_setopt($this->_ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($this->_ch, CURLOPT_URL, $this->_base_uri.$url);
    curl_setopt($this->_ch, CURLOPT_POSTFIELDS, json_encode($body));
    return json_decode(curl_exec($this->_ch));
  }
  public function account() {
    return $this->get('/account');
  }
  public function workspaces() {
    return $this->get('/workspaces');
  }
  public function projects() {
    return $this->get("/workspaces/{$this->workspace_id}/projects");
  }
  
  public function upcoming_tasks($member_id = 0, $limit = 200) {
  	// always do flat and set a limit
  	$filter = '?flat=true&limit='.$limit;
  	if ($member_id>0){
	  	$filter .= '&member_id='.$member_id;
	}
    return $this->get("/workspaces/{$this->workspace_id}/upcoming_tasks" . $filter);
  }

  public function tasks($today_only = true, $task_ids = array()) {
  	$filter = '';
  	$limit = 200;
  	if (!empty($task_ids)){
  		$filter = '?filter_conjunction=OR';
  		$task_ids = array_unique($task_ids);
  		foreach($task_ids as $id){
  			$filter .= '&filter[]=id=' . $id;
  		}
  		$filter .= '&limit=' . $limit;
  	}
  	if ($today_only){
  		$filter .= ($filter == '') ? '?' : '&';
  		$filter .= 'limit=' . $limit . '&expected_start=' . date("Y-m-d");
  	}
    return $this->get("/workspaces/{$this->workspace_id}/tasks" . $filter);
  }
  public function members($staffersOnly = true) {
  	//$filter = '?filter[]=is_virtual is false';	// did not work :(
  	$allMembers = $this->get("/workspaces/{$this->workspace_id}/members");
  	if ($staffersOnly){
  		$members = array();
  		foreach($allMembers as $member){
  			if ($member->id > 0 && !$member->is_virtual && $member->access_level != 'portal'){
  				$members[] = $member;
  			}
  		}
	  	return $members;
  	} else {
  		return $allMembers;
  	}
  }
  public function timesheets($this_week = true) {
  	$filter = '';
  	if ($this_week) {
  		$monday = strtotime('last monday');
  		$sunday = strtotime("+7 day", $monday);
  		$filter = '?start_date='.date('Y-m-d', $monday).'&end_date=' . date('Y-m-d', $sunday);
  	}
  	return $this->get("/workspaces/{$this->workspace_id}/timesheets" . $filter);
  }
  public function timesheet($member_id) {
  	return $this->get("/workspaces/{$this->workspace_id}/timesheets?member_id={$member_id}");	//201507
  }

  public function timeentries($date = '', $with_tasks = true) {
  	$filter = '';
  	if ($date != '') {
  		$start = $date;
  		$end = strtotime("+1 day", $date);
  		$filter = '?start_date='.date('Y-m-d', $start).'&end_date=' . date('Y-m-d', $end);
  	}
  	$entries = $this->get("/workspaces/{$this->workspace_id}/timesheet_entries" . $filter);
  	if ($with_tasks){
  		$entry_map = array();
  		$task_filter = '?';
  		$task_ids = array();
  		foreach($entries as $key => $entry){
  			$entry_map[$entry->item_id] = $key;
  			$task_ids[] = $entry->item_id;
  		}
  		$tasks = $this->tasks(false, $task_ids);
  		foreach($tasks as $task){
  			if (isset($entry_map[$task->id])){
	  			$key = $entry_map[$task->id];
	  			if (isset($entries[$key])){
	  				//$entries[$key]->task = $task;
		  			$entries[$key]->task_name = $task->name;
		  			$entries[$key]->task_client_name = $task->client_name;
		  			$entries[$key]->task_parent_crumbs = $task->parent_crumbs;
	  			}
	  		}
  		}
  	}
  	return $entries;
  }

  public function create_task($data) {
    return $this->post("/workspaces/{$this->workspace_id}/tasks", array("task"=>$data));
  }
  public function update_task($data) {
    return $this->put("/workspaces/{$this->workspace_id}/tasks/{$data['id']}", array("task"=>$data));
  }
}	// end class

function getPrevWorkday(){
	$day_of_week = date("N", time()) - 1;
	if ($day_of_week == 0){
		// today is monday - return last friday
		return strtotime('-3 days');
	} else {
		return strtotime('-1 day');
	}
}

function processTimeEntries($timeentries, $members){
	$results = array();
	$timeByMember = array();
	$timeByMemberOutput = array();
	$totalTimeByMember = array();
	$task_ids = array();
	foreach($timeentries as $entry){
		$task_ids[] = $entry->item_id;
		if (!isset($timeByMember[$entry->member_id])){ 
			$timeByMember[$entry->member_id] = array();
			$timeByMemberOutput[$entry->member_id] = array();
		}
		$timeByMember[$entry->member_id][] = $entry;

		if (!isset($totalTimeByMember[$entry->member_id])){ 
			$totalTimeByMember[$entry->member_id] = 0;
		}
		$totalTimeByMember[$entry->member_id] += floatval($entry->work);
		/* slimmed down output
		$newEntry = array(
			'note' => $entry->note , 
			'work' => $entry->work, 
			'item_id' => $entry->item_id, 
			'project_id' => $entry->project_id,
			);
		if (property_exists($entry, 'task_name')){
		  	$newEntry['task_name'] = $entry->task_name;
		  	$newEntry['task_client_name'] = $entry->task_client_name;
		  	$newEntry['task_parent_crumbs'] = $entry->task_parent_crumbs;
		}
		$timeByMemberOutput[$entry->member_id][] = $newEntry;
		*/
		$timeByMemberOutput[$entry->member_id][] = $entry;
	}
	foreach($members as $member){
		if ($member->id > 0 && !$member->is_virtual && $member->access_level != 'portal'){
			$result = array(
				'member_id' => $member->id,
				'first_name' => $member->first_name,
				'last_name' => $member->last_name,
				'email' => $member->email,
				'avatar_url' => $member->avatar_url,
				'entries' => ( (isset($timeByMemberOutput[$member->id])) ? $timeByMemberOutput[$member->id] : array() ),
				'totalhours' => ( (isset($totalTimeByMember[$member->id])) ? $totalTimeByMember[$member->id] : 0 ),
				//'timesheet' => $sheet,
				//'status' => $status,
				//'yesterdays_hours' => $prev_days_hours,
			);
			$result['status'] = (intval($result['totalhours'] >= 8)) ? 'complete' : 'critical';
			$results[] = $result;
		}
	}
	return $results;	
}

// return an array of members and their status from the last work day
function processTimesheets($timesheets, $members){
	$alerts = array();
	$sheetByMember = array();
	foreach($timesheets as $sheet){
		$sheetByMember[$sheet->member_id] = $sheet;
	}

	foreach($members as $member){
		if ($member->id > 0 && !$member->is_virtual && $member->access_level != 'portal'){
			$sheet = (isset($sheetByMember[$member->id])) ? $sheetByMember[$member->id] : null;
			$status = 'none';
			// get the last working day and if it's less than 8 then throw an error
			$day_of_week = date("N", time()) - 1;
			$prev_work_day = ($day_of_week == 0) ? 4 : ($day_of_week - 1);
			$prev_days_hours = ($sheet !== null) ? $sheet->daily_totals[$prev_work_day] : 0;
			if (intval($prev_days_hours) >= 8){
				$status = 'complete';
			} else {
				$status = 'critical';
			}
			$alert = array(
				'member_id' => $member->id,
				'first_name' => $member->first_name,
				'last_name' => $member->last_name,
				'email' => $member->email,
				'avatar_url' => $member->avatar_url,
				'timesheet' => $sheet,
				'status' => $status,
				'yesterdays_hours' => $prev_days_hours,
				//'day_of_week' => $day_of_week,
				//'prev_work_day' => $prev_work_day,
			);
			$alerts[] = $alert;
		}
	}
	return $alerts;
}

// jed - changing
// liquidplanner service!
$result = array(
	'success' => false,
	'message' => 'nothing done'
);


$action = 'status';
if (isset($_REQUEST['action'])){
	$action = $_REQUEST['action'];
}

// email/pass
$email = $_REQUEST['email'];
$password = $_REQUEST['password'];
$workspace_id = $_REQUEST['workspace_id'];
//
$lp = new LiquidPlanner($email, $password);
// stashish this so we limit the calls - and it should not change for us...
$lp->workspace_id = $workspace_id;

// changing defaults
//$action = 'yesterday_todos';
$result['workspace_id'] = $workspace_id;
switch ($action) {
	case 'todays_todos':
	    $members = $lp->members(true);
	    $todos = array();
	    foreach ($members as $member) {
	    	$tasks = $lp->upcoming_tasks($member->id, 20);
			$todos[] = array(
				'member_id' => $member->id,
				'first_name' => $member->first_name,
				'last_name' => $member->last_name,
				'email' => $member->email,
				'avatar_url' => $member->avatar_url,
				'tasks' => $tasks,
				);
	    }
	    //$todos = processTodos($tasksByMember, $members);
	    $result['tasks_today'] = $todos;
	    //$result['tasksByMember'] = $tasksByMember;
		break;
	case 'yesterdays_todos':
		$prev_work_day = getPrevWorkday();
	    $members = $lp->members(true);
	    $timeentries = $lp->timeentries($prev_work_day);	// all timesheet entries from yesterday
	   	$entries = processTimeEntries($timeentries, $members);
	   	$result['tasks_yesterday'] = $entries;
	    //$result['members'] = $members;
	    $result['timeentries'] = $timeentries;
	    $result['prev'] = date('Y-m-d',$prev_work_day);
		break;
	
	default:
	    // get the members and timesheets
	    $members = $lp->members(true);
	    $timesheets = $lp->timesheets(true);
	    $statuses = processTimesheets($timesheets, $members);
		$result['statuses'] = $statuses;
	    //$result['members'] = $members;
	    //$result['timesheets'] = $timesheets;
		// return a list of workspaces
		$result['success'] = true;
		$result['message'] = 'ok';

		break;
}



header('Content-type: application/json');
die(json_encode($result));
