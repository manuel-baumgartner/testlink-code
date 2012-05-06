<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	tlTestPlanMetrics.class.php
 * @package 	TestLink
 * @author 		Kevin Levy, franciscom
 * @copyright 	2004-2012, TestLink community 
 * @link 		http://www.teamst.org/index.php
 * @uses		config.inc.php 
 * @uses		common.php 
 *
 * @internal revisions
 * @since 1.9.4
 *
 * 20120429 - franciscom -	TICKET 4989: Reports - Overall Build Status - refactoring and final business logic
 *							new method getOverallBuildStatusForRender()
 *
 * 20120419 - franciscom - new method getExecCountersByBuildStatusOnlyWithTesterAssignment()
 *
 **/

/**
 * This class is encapsulates most functionality necessary to query the database
 * for results to publish in reports.  
 * It returns data structures to the gui layer in a manner that are easy to display 
 * in smarty templates.
 * 
 * @package TestLink
 * @author kevinlevy
 */
class tlTestPlanMetrics extends testPlan
{
	/** @var resource references passed in by constructor */
	var  $db = null;

	/** @var object class references passed in by constructor */
	private $tplanMgr = null;
	private $testPlanID = -1;
	private	$tprojectID = -1;
	private	$testCasePrefix='';

	private $priorityLevelsCfg='';
	private $map_tc_status;
	private $tc_status_for_statistics;
	private $notRunStatusCode;
	private $statusCode;
	private $execTaskCode;

	/** 
	 * class constructor 
	 * @param resource &$db reference to database handler
	 **/    
	function __construct(&$db)
	{
		$this->resultsCfg = config_get('results');
		$this->testCaseCfg = config_get('testcase_cfg');

  		$this->db = $db;
  		parent::__construct($db);

  		$this->map_tc_status = $this->resultsCfg['status_code'];
    
    	// This will be used to create dynamically counters if user add new status
    	foreach( $this->resultsCfg['status_label_for_exec_ui'] as $tc_status_verbose => $label)
    	{
      		$this->tc_status_for_statistics[$tc_status_verbose] = $this->map_tc_status[$tc_status_verbose];
    	}
    	if( !isset($this->resultsCfg['status_label_for_exec_ui']['not_run']) )
    	{
      		$this->tc_status_for_statistics['not_run'] = $this->map_tc_status['not_run'];  
    	}
    	$this->notRunStatusCode = $this->tc_status_for_statistics['not_run'];
    	
		$this->statusCode = array_flip(array_keys($this->resultsCfg['status_label_for_exec_ui']));
		foreach($this->statusCode as $key => $dummy)
		{
			$this->statusCode[$key] = $this->resultsCfg['status_code'][$key];	
		}
    	
    	$this->execTaskCode = intval($this->assignment_types['testcase_execution']['id']);

	} // end results constructor



	public function getStatusConfig() 
	{
		return $this->tc_status_for_statistics;
	}


	/**
	 * Function returns prioritized test result counter
	 * 
	 * @param timestamp $milestoneTargetDate - (optional) milestone deadline
	 * @param timestamp $milestoneStartDate - (optional) milestone start date
	 * @return array with three priority counters
	 */
	public function getPrioritizedResults($tplanID,$milestoneTargetDate = null, $milestoneStartDate = null)
	{
		$output = array (HIGH=>0,MEDIUM=>0,LOW=>0);
		
		for($urgency=1; $urgency <= 3; $urgency++)
		{
			for($importance=1; $importance <= 3; $importance++)
			{	
				$sql = "SELECT COUNT(DISTINCT(TPTCV.id )) " .
					" FROM {$this->tables['testplan_tcversions']} TPTCV " .
					" JOIN {$this->tables['executions']} E ON " .
					" TPTCV.tcversion_id = E.tcversion_id " .
					" JOIN {$this->tables['tcversions']} TCV ON " .
					" TPTCV.tcversion_id = TCV.id " .
					" WHERE TPTCV.testplan_id = {$tplanID} " .
					" AND TPTCV.platform_id = E.platform_id " .
					" AND E.testplan_id = {$tplanID} " .
					" AND NOT E.status = '{$this->map_tc_status['not_run']}' " . 
					" AND TCV.importance={$importance} AND TPTCV.urgency={$urgency}";
				
				// Milestones did not handle start and target date properly
				$end_of_the_day = " 23:59:59";
				$beginning_of_the_day = " 00:00:00";
				
				if( !is_null($milestoneTargetDate) )
				{
					$sql .= " AND execution_ts < '" . $milestoneTargetDate . $end_of_the_day ."'";
				}
				
				if( !is_null($milestoneStartDate) )
				{
					$sql .= " AND execution_ts > '" . $milestoneStartDate . $beginning_of_the_day ."'";
				}
				
				$tmpResult = $this->db->fetchOneValue($sql);
				// parse results into three levels of priority
				
				//BUGID 4418 - clean up priority usage
				$priority = priority_to_level($urgency*$importance);
				$output[$priority] = $output[$priority] + $tmpResult;
			}
		}
		
		return $output;
	}

	/**
	 * Function returns prioritized test case counter (in Test Plan)
	 * 
	 * @return array with three priority counters
	 */
	public function getPrioritizedTestCaseCounters($tplanID)
	{
		$output = array (HIGH=>0,MEDIUM=>0,LOW=>0);
		
		/** @TODO - REFACTOR IS OUT OF STANDARD MAGIC NUMBERS */
		for($urgency=1; $urgency <= 3; $urgency++)
		{
			for($importance=1; $importance <= 3; $importance++)
			{	
				// get total count of related TCs
				$sql = "SELECT COUNT( TPTCV.id ) FROM {$this->tables['testplan_tcversions']} TPTCV " .
						" JOIN {$this->tables['tcversions']} TCV ON TPTCV.tcversion_id = TCV.id " .
						" WHERE TPTCV.testplan_id = " . $tplanID .
			    		" AND TCV.importance={$importance} AND TPTCV.urgency={$urgency}";

				$tmpResult = $this->db->fetchOneValue($sql);
				
				// clean up priority usage
				$priority = priority_to_level($urgency*$importance);
				$output[$priority] = $output[$priority] + $tmpResult;
			}
		}
					
		return $output;
	}


	/**
	 * 
	 */
	function getMilestonesMetrics($tplanID, $milestoneSet=null)
	{        
		$results = array();
		// get amount of test cases for each execution result + total amount of test cases
        $planMetrics = $this->getStatusTotals($tplanID);
		$milestones =  is_null($milestoneSet) ? $this->get_milestones($tplanID) : $milestoneSet;
		// get amount of test cases for each priority for test plan			
		$priorityCounters = $this->getPrioritizedTestCaseCounters($tplanID);
        $pc = array(LOW => 'result_low_percentage', MEDIUM => 'result_medium_percentage',
                    HIGH => 'result_high_percentage' );
        
        $checks = array(LOW => 'low_percentage', MEDIUM => 'medium_percentage',
                        HIGH => 'high_percentage' );

        $on_off = array(LOW => 'low_incomplete', MEDIUM => 'medium_incomplete',
                        HIGH => 'high_incomplete' );
        
        // Important:
        // key already defined on item: high_percentage,medium_percentage,low_percentage
		foreach($milestones as $item)
		{
            $item['tcs_priority'] = $priorityCounters;
		    $item['tc_total'] = $planMetrics['total'];
		    // get amount of executed test cases for each priority before target_date
		    $item['results'] = $this->getPrioritizedResults($tplanID, $item['target_date'], $item['start_date']);
            $item['tc_completed'] = 0;
            
            // calculate percentage of executed test cases for each priority
            foreach( $pc as $key => $item_key)
            {
            	$item[$item_key] = $this->get_percentage($priorityCounters[$key], $item['results'][$key]);
            	$item['tc_completed'] += $item['results'][$key];
            }
            
            // amount of all executed tc with any priority before target_date / all test cases
            $item['percentage_completed'] = $this->get_percentage($item['tc_total'], $item['tc_completed']);
            
            foreach( $checks as $key => $item_key)
            {
            	// add 1 decimal places to expected percentages
            	$item[$checks[$key]] = number_format($item[$checks[$key]], 1);
            	
            	// check if target for each priority is reached
            	// show target as reached if expected percentage is greater than executed percentage
            	$item[$on_off[$key]] = ($item[$checks[$key]] > $item[$pc[$key]]) ? ON : OFF;
            }
            // BUGID 3820
		    $results[$item['id']] = $item;
	  	}
		return $results;
	}
	
	
	/**
	 * calculate percentage and format
	 * 
	 * @param int $total Total count
	 * @param int $parameter a parameter count
	 * @return string formatted percentage
	 */
	function get_percentage($total, $parameter)
	{
		$percentCompleted = $total > 0 ? (($parameter / $total) * 100) : 100;
		return number_format($percentCompleted,1);
	}


	// Work on ALL ACTIVE BUILDS IGNORING Platforms
	function getExecCountersByBuildExecStatus($id, $opt=null)
	{
		//echo 'QD - <b><br>' . __FUNCTION__ . '</b><br>';
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

		$my['opt'] = array('getUnassigned' => false);
		$my['opt'] = array_merge($my['opt'], (array)$opt);
		
		$activeBuilds = array_keys($ab=$this->get_builds($id,testplan::ACTIVE_BUILDS));
		$buildsInClause = implode(",",$activeBuilds);
		$execCode = intval($this->assignment_types['testcase_execution']['id']);
		
		// This subquery is BETTER than the VIEW, need to understand why
		// Last Executions By Build (LEBB)
		$sqlLEBB = 	" SELECT EE.tcversion_id,EE.testplan_id,EE.build_id,MAX(EE.id) AS id " .
				  	" FROM {$this->tables['executions']} EE " . 
				   	" WHERE EE.testplan_id=" . intval($id) . 
					" AND EE.build_id IN ({$buildsInClause}) " .
				   	" GROUP BY EE.tcversion_id,EE.testplan_id,EE.build_id ";
		
		
		// Common sentece - reusable
		$sqlExec = 	"/* {$debugMsg} */" . 
					" SELECT UA.build_id,COALESCE(E.status,'{$this->notRunStatusCode}') AS status, count(0) AS exec_qty " .

					" /* Get feature id with Tester Assignment */ " .
					" FROM {$this->tables['testplan_tcversions']} TPTCV " .

					" /*LEFTPLACEHOLDER*/ JOIN {$this->tables['user_assignments']} UA " .
					" ON UA.feature_id = TPTCV.id " .
					" AND UA.build_id IN ({$buildsInClause}) AND UA.type = {$execCode} " .

					" /* GO FOR Absolute LATEST exec ID by BUILD IGNORE  Platform */ " .
					" LEFT OUTER JOIN ({$sqlLEBB}) AS LEBB " .
					" ON  LEBB.testplan_id = TPTCV.testplan_id " .
					" AND LEBB.tcversion_id = TPTCV.tcversion_id " .
					" AND LEBB.testplan_id = " . intval($id) .

					" /* Get execution status INCLUDING NOT RUN */ " .
					" LEFT OUTER JOIN {$this->tables['executions']} E " .
					" ON  E.id = LEBB.id " .
					" AND E.build_id = LEBB.build_id " .
					" AND E.build_id IN ({$buildsInClause}) ";
				
				
		// get all execution status from DB Only for test cases with tester assigned			
		$sql = 	$sqlExec .		
				" /* FILTER ONLY ACTIVE BUILDS on target test plan */ " .
				" WHERE TPTCV.testplan_id=" . intval($id) . 
				" AND UA.build_id IN ({$buildsInClause}) " .
				" GROUP BY build_id,status ";
	
		// 
		//echo 'QD - <br><b>' . __FUNCTION__ . '</b><br>'; 
		//echo 'QD - <br>' . $sql . '<br>';
		
        $exec['with_tester'] = (array)$this->db->fetchMapRowsIntoMap($sql,'build_id','status');              

		if( $my['opt']['getUnassigned'] )
		{
			// NEED TO CHECK 
			// get all execution status from DB Only for test cases WITHOUT tester assigned			
			$sqlExecLOJ = str_replace('/*LEFTPLACEHOLDER*/',' LEFT OUTER ',$sqlExec);
			$sql = $sqlExecLOJ .
					" WHERE LEBB.testplan_id=" . intval($id) . ' AND UA.feature_id IS NULL ' .  
					" GROUP BY E.build_id,E.status ";
		
	        $exec['wo_tester'] = (array)$this->db->fetchMapRowsIntoMap($sql,'build_id','status');              
		}


		// Need to Add info regarding:
		// - Add info for ACTIVE BUILD WITHOUT any execution. ???
		//   Hmm, think about Need to check is this way is better that request DBMS to do it.
		// - Execution status that have not happened
		foreach($exec as &$elem)
		{                             
			$itemSet = array_keys($elem);
			foreach($itemSet as $itemID)
			{
				foreach($this->statusCode as $verbose => $code)
				{
					if(!isset($elem[$itemID][$code]))
					{
						$elem[$itemID][$code] = array('build_id' => $itemID,'status' => $code, 'exec_qty' => 0);			
					}												   
				}
			}
		}
		
		// get total assignments by BUILD ID
		$sql = 	"/* $debugMsg */ ".
				" SELECT COUNT(0) AS qty, UA.build_id " . 
				" FROM {$this->tables['user_assignments']} UA " .
				" JOIN {$this->tables['testplan_tcversions']} TPTCV ON TPTCV.id = UA.feature_id " .
				" WHERE UA. build_id IN ( " . $buildsInClause . " ) " .
				" AND UA.type = {$execCode} " . 
				" GROUP BY build_id";

		//$exec['total_assigned'] = (array)$this->db->fetchRowsIntoMap($sql,'build_id');
		$exec['total'] = (array)$this->db->fetchRowsIntoMap($sql,'build_id');
		$exec['active_builds'] = $ab;

		return $exec;
	}
	
                                      
	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120429 - franciscom - TICKET 4989: Reports - Overall Build Status - refactoring and final business logic
	 **/
	function getOverallBuildStatusForRender($id, $totalKey='total_assigned')
	{
	   	$renderObj = null;
		$code_verbose = $this->getStatusForReports();
	    $labels = $this->resultsCfg['status_label'];
	    
		$metrics = $this->getExecCountersByBuildExecStatus($id);
	   	if( !is_null($metrics) )
	   	{
	   		$renderObj = new stdClass();

			// Creating item list this way will generate a row also for
			// ACTIVE BUILDS were ALL TEST CASES HAVE NO TESTER ASSIGNMENT
			// $buildList = array_keys($metrics['active_builds']);
			
			// Creating item list this way will generate a row ONLY FOR
			// ACTIVE BUILDS were TEST CASES HAVE TESTER ASSIGNMENT
			$buildList = array_keys($metrics['with_tester']);
			$renderObj->info = array();	
		    foreach($buildList as $buildID)
		    {
				$totalRun = 0;
		    	$renderObj->info[$buildID]['build_name'] = $metrics['active_builds'][$buildID]['name']; 	
		    	$renderObj->info[$buildID][$totalKey] = $metrics['total'][$buildID]['qty']; 	

				$renderObj->info[$buildID]['details'] = array();
				
				$rf = &$renderObj->info[$buildID]['details'];
				foreach($code_verbose as $statusCode => $statusVerbose)
				{
					$rf[$statusVerbose] = array('qty' => 0, 'percentage' => 0);
					$rf[$statusVerbose]['qty'] = $metrics['with_tester'][$buildID][$statusCode]['exec_qty']; 	
					
					if( $renderObj->info[$buildID][$totalKey] > 0 ) 
					{
						$rf[$statusVerbose]['percentage'] = number_format(100 * 
																		  ($rf[$statusVerbose]['qty'] / 
															 			   $renderObj->info[$buildID][$totalKey]),1);
					}
					
					$totalRun += $statusVerbose == 'not_run' ? 0 : $rf[$statusVerbose]['qty'];
				}
				$renderObj->info[$buildID]['percentage_completed'] =  number_format(100 * 
																					($totalRun / 
																					 $renderObj->info[$buildID][$totalKey]),1);
		    }
		   	
		    foreach($code_verbose as $status_verbose)
		    {
		    	$l18n_label = isset($labels[$status_verbose]) ? lang_get($labels[$status_verbose]) : 
		                      lang_get($status_verbose); 
		    
		    	$renderObj->colDefinition[$status_verbose]['qty'] = $l18n_label;
		    	$renderObj->colDefinition[$status_verbose]['percentage'] = '[%]';
		    }
	
		}
		return $renderObj;
	}



	/** 
	 *    
	 * If no build set has been provided consider ONLY ACTIVE BUILDS   
	 *    
	 *    
	 */    
	function getExecCountersByKeywordExecStatus($id, $filters=null, $opt=null)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
		$safe_id = intval($id);
		list($my,$builds,$sqlStm) = $this->helperGetExecCounters($safe_id, $filters, $opt);
		
		
		// may be too brute force but ...
		if( ($tprojectID = $my['opt']['tprojectID']) == 0 )
		{
			$info = $this->tree_manager->get_node_hierarchy_info($safe_id);
			$tprojectID = $info['parent_id'];
		} 
		$tproject_mgr = new testproject($this->db);
		$keywordSet = $tproject_mgr->get_keywords_map($tprojectID);
		$tproject_mgr = null;
		
		
		// This subquery is BETTER than the VIEW, need to understand why
		// Latest Execution Ignoring Build and Platform
		$sqlLE = $sqlStm['LE'];
		
		// DISTINCT is needed when you what to get data ONLY FOR test cases with assigned testers,
		// because we are in addition working on a BUILD SET, not on a SINGLE build,
		// we are usign IN clause, and this will have a NOT wanted multiplication effect
		// on this query.
		// This do not happens with other queries on other metric attributes,
		// be careful before changing other queries.
		// 
		$sqlUnionAK	=	"/* {$debugMsg} sqlUnionAK - executions */" . 
						" SELECT DISTINCT NHTCV.parent_id, TCK.keyword_id," .
						" COALESCE(E.status,'{$this->notRunStatusCode}') AS status " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .
						
						$sqlGetAssignedFeatures .

						" /* GO FOR Absolute LATEST exec ID IGNORE BUILD AND Platform */ " .
						" JOIN ({$sqlLE}) AS LE " .
						" ON  LE.testplan_id = TPTCV.testplan_id " .
						" AND LE.tcversion_id = TPTCV.tcversion_id " .
						" AND LE.testplan_id = " . $safe_id .

						" /* Get execution status WRITTEN on DB */ " .
						" JOIN {$this->tables['executions']} E " .
						" ON  E.id = LE.id " .

						" /* Get ONLY Test case versions that has AT LEAST one Keyword assigned */ ".
						" JOIN {$this->tables['nodes_hierarchy']} NHTCV " .
						" ON NHTCV.id = TPTCV.tcversion_id " .
						" JOIN {$this->tables['testcase_keywords']} TCK " .
						" ON TCK.testcase_id = NHTCV.parent_id " .
					
						" WHERE TPTCV.testplan_id=" . $safe_id .
						$builds->whereAddExec;

		//echo 'QD - <br>' . $sqlUnionAK . '<br>';
	
		// DISTINCT is needed when you what to get data ONLY FOR test cases with assigned testers,
		// because we are in addition working on a BUILD SET, not on a SINGLE build,
		// we are usign IN clause, and this will have a NOT wanted multiplication effect
		// on this query.
		// This do not happens with other queries on other metric attributes,
		// be careful before changing other queries.
		// 
		$sqlUnionBK	=	"/* {$debugMsg} sqlUnionBK - NOT RUN */" . 
						" SELECT DISTINCT NHTCV.parent_id, TCK.keyword_id," .
						" COALESCE(E.status,'{$this->notRunStatusCode}') AS status " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .
						
						$sqlStm['getAssignedFeatures'] .

						" /* Get REALLY NOT RUN => BOTH LE.id AND E.id ON LEFT OUTER see WHERE  */ " .
						" LEFT OUTER JOIN ({$sqlLE}) AS LE " .
						" ON  LE.testplan_id = TPTCV.testplan_id " .
						" AND LE.tcversion_id = TPTCV.tcversion_id " .
						" AND LE.testplan_id = " . $safe_id .
						" LEFT OUTER JOIN {$this->tables['executions']} E " .
						" ON  E.tcversion_id = TPTCV.tcversion_id " .
						" AND E.testplan_id = TPTCV.testplan_id " .
						" AND E.platform_id = TPTCV.platform_id " .
						$builds->joinAdd .

						" /* Get ONLY Test case versions that has AT LEAST one Keyword assigned */ ".
						" JOIN {$this->tables['nodes_hierarchy']} NHTCV " .
						" ON NHTCV.id = TPTCV.tcversion_id " .
						" JOIN {$this->tables['testcase_keywords']} TCK " .
						" ON TCK.testcase_id = NHTCV.parent_id " .

						" /* FILTER BUILDS in set on target test plan */ " .
						" WHERE TPTCV.testplan_id=" . $safe_id . 
						$builds->whereAddNotRun .
	
						" /* Get REALLY NOT RUN => BOTH LE.id AND E.id NULL  */ " .
						" AND E.id IS NULL AND LE.id IS NULL";

		//echo 'QD - <br>' . $sqlUnionBK . '<br>';

		$sql =	" /* {$debugMsg} UNION Without ALL CLAUSE => DISCARD Duplicates */" .
				" SELECT keyword_id,status, count(0) AS exec_qty " .
				" FROM ($sqlUnionAK UNION $sqlUnionBK ) AS SQK " .
				" GROUP BY keyword_id,status ";

		// 
		//echo 'QD -<br><b>' . __FUNCTION__ . '</b><br>'; 
		//echo 'QD - ' . $sql . '<br>';
        $exec['with_tester'] = (array)$this->db->fetchMapRowsIntoMap($sql,'keyword_id','status');              


		// complete status domain for each keyword		
		// foreach($exec as &$elem)
		// {
		// 	foreach($elem as $keywordID => $dummy)
		// 	{
		// 		foreach($this->statusCode as $verbose => $code)
		// 		{
		// 			if(!isset($elem[$keywordID][$code]))
		// 			{
		// 				$elem[$keywordID][$code] = array('keyword_id' => $keywordID,'status' => $code, 'exec_qty' => 0);			
		// 			}						
		// 		}
		// 	}
		// }
		$this->helperCompleteStatusDomain($exec,'keyword_id');
           

		// On next queries:
		// we need to use distinct, because IF NOT we are going to get one record
		// for each build where test case has TESTER ASSIGNMENT
		//
		// $exec['total_assigned'] = null;
		$exec['total'] = null;
		$exec['key4total'] = 'total';
		if( $my['opt']['getOnlyAssigned'] )
		{
			// $exec['key4total'] = 'total_assigned';
			$sql = 	"/* $debugMsg */ ".
					" SELECT COUNT(0) AS qty, keyword_id " .
					" FROM " . 
					" ( /* Get test case,keyword pairs */ " .
					"  SELECT DISTINCT NHTCV.parent_id, TCK.keyword_id " . 
					"  FROM {$this->tables['user_assignments']} UA " .
					"  JOIN {$this->tables['testplan_tcversions']} TPTCV ON TPTCV.id = UA.feature_id " .
        	
					"  /* Get ONLY Test case versions that has AT LEAST one Keyword assigned */ ".
					"  JOIN {$this->tables['nodes_hierarchy']} NHTCV " .
					"  ON NHTCV.id = TPTCV.tcversion_id " .
					"  JOIN {$this->tables['testcase_keywords']} TCK " .
					"  ON TCK.testcase_id = NHTCV.parent_id " .
					"  WHERE UA. build_id IN ( " . $builds->inClause . " ) " .
					"  AND UA.type = {$execCode} ) AS SQK ".
					" GROUP BY keyword_id";
		}
		else
		{
			// $exec['key4total'] = 'total';
			$sql = 	"/* $debugMsg */ ".
					" SELECT COUNT(0) AS qty, keyword_id " .
					" FROM " . 
					" ( /* Get test case,keyword pairs */ " .
					"  SELECT DISTINCT NHTCV.parent_id, TCK.keyword_id " . 
					"  FROM {$this->tables['testplan_tcversions']} TPTCV " .
        	
					"  /* Get ONLY Test case versions that has AT LEAST one Keyword assigned */ ".
					"  JOIN {$this->tables['nodes_hierarchy']} NHTCV " .
					"  ON NHTCV.id = TPTCV.tcversion_id " .
					"  JOIN {$this->tables['testcase_keywords']} TCK " .
					"  ON TCK.testcase_id = NHTCV.parent_id " .
					"  WHERE TPTCV.testplan_id = " . $safe_id . " ) AS SQK ".
					" GROUP BY keyword_id";
		}	

		$exec[$exec['key4total']] = (array)$this->db->fetchRowsIntoMap($sql,'keyword_id');
		$exec['keywords'] = $keywordSet;

		return $exec;
	}


	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120429 - franciscom - 
	 **/
	function getStatusTotalsByKeywordForRender($id,$filters=null,$opt=null)
	{
		$renderObj = $this->getStatusTotalsByItemForRender($id,'keyword',$filters,$opt);
		return $renderObj;
	}



	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120429 - franciscom - 
	 */
	function getExecCountersByPlatformExecStatus($id, $filters=null, $opt=null)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
		list($my,$builds,$sqlStm) = $this->helperGetExecCounters($id, $filters, $opt);

		$getOpt = array('outputFormat' => 'mapAccessByID', 'outputDetails' => 'name', 'addIfNull' => true);
		$platformSet = $this->getPlatforms($id,$getOpt);
		$safe_id = intval($id);	

		// Latest Executions By Platform (LEBP)
		$sqlLEBP = 	$sqlStm['LEBP'];
		
		$sqlUnionAP	=	"/* {$debugMsg} sqlUnionAP - executions */" . 
						" SELECT TPTCV.platform_id, COALESCE(E.status,'{$this->notRunStatusCode}') AS status " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .
						
						$sqlStm['getAssignedFeatures'] .

						" /* GO FOR Absolute LATEST exec ID IGNORE BUILD */ " .
						" JOIN ({$sqlLEBP}) AS LEBP " .
						" ON  LEBP.testplan_id = TPTCV.testplan_id " .
						" AND LEBP.tcversion_id = TPTCV.tcversion_id " .
						" AND LEBP.testplan_id = " . $safe_id .

						" /* Get execution status WRITTEN on DB */ " .
						" JOIN {$this->tables['executions']} E " .
						" ON  E.id = LEBP.id " .

			
						" WHERE TPTCV.testplan_id=" . $safe_id .
						$builds->whereAddExec;

		//echo 'QD - <br>' . $sqlUnionAP . '<br>';
		
		
		
		
		
		
// ----------------------------------------------------------------------------------------------------------		
		$sqlExec = 	"/* {$debugMsg} */" . 
					" SELECT TPTCV.platform_id,COALESCE(E.status,'{$this->notRunStatusCode}') AS status, count(0) AS exec_qty " .

					" /* Get feature id with Tester Assignment */ " .
					" FROM {$this->tables['testplan_tcversions']} TPTCV " .

					" /*LEFTPLACEHOLDER*/ JOIN {$this->tables['user_assignments']} UA " .
					" ON UA.feature_id = TPTCV.id " .
					" AND UA.build_id IN ({$buildsInClause}) AND UA.type = {$execCode} " .

					" /* GO FOR Absolute LATEST exec ID (is exists), IGNORE BUILD */ " .
					" LEFT OUTER JOIN ({$sqlLEBP}) AS LEBP " .
					" ON  LEBP.tcversion_id = TPTCV.tcversion_id " .
					" AND LEBP.platform_id = TPTCV.platform_id " .
					" AND LEBP.testplan_id = TPTCV.testplan_id " .
					" AND LEBP.testplan_id = " . intval($id) .

					" /* Get execution status INCLUDING NOT RUN */ " .
					" LEFT OUTER JOIN {$this->tables['executions']} E " .
					" ON  E.id = LEBP.id " .
					" AND E.build_id IN ({$buildsInClause}) ";
				
				
		// get all execution status from DB AND NOT RUN, Only for test cases with tester assigned			
		$sql = 	$sqlExec .		
				" /* FILTER ONLY ACTIVE BUILDS on target test plan */ " .
				" WHERE TPTCV.testplan_id=" . intval($id) . 
				" AND UA.build_id IN ({$buildsInClause}) " .
				" GROUP BY platform_id,status ";
	
		// 
		//echo 'QD - <br><b>' . __FUNCTION__ . '</b><br>'; 
		//echo 'QD - <br>' . $sql . '<br>';

        $exec['with_tester'] = (array)$this->db->fetchMapRowsIntoMap($sql,'platform_id','status');              


		// add basic data for exec status not found on DB.
		
		// foreach($exec as &$elem)
		// {
		// 	foreach($elem as $itemID => $dummy)
		// 	{
		//  		foreach($this->statusCode as $verbose => $code)
		//  		{
		//  			if(!isset($elem[$itemID][$code]))
		//  			{
		//  				$elem[$itemID][$code] = array('keyword_id' => $itemID,'status' => $code, 'exec_qty' => 0);			
		//  			}						
		//  		}
		//  	}
		// }

		// get total assignments by Platform id
		$sql = 	"/* $debugMsg */ ".
				" SELECT COUNT(0) AS qty, TPTCV.platform_id " . 
				" FROM {$this->tables['user_assignments']} UA " .
				" JOIN {$this->tables['testplan_tcversions']} TPTCV ON TPTCV.id = UA.feature_id " .

				" WHERE UA. build_id IN ( " . $buildsInClause . " ) " .
				" AND UA.type = {$execCode} " . 
				" GROUP BY platform_id";

		// $exec['total_assigned'] = (array)$this->db->fetchRowsIntoMap($sql,'platform_id');
		$exec['total'] = (array)$this->db->fetchRowsIntoMap($sql,'platform_id');
		$exec['platforms'] = $platformSet;

	
		return $exec;
	}



	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120429 - franciscom - 
	 */
	function getStatusTotalsByPlatformForRender($id,$filters=null,$opt=null)
	{
		$renderObj = $this->getStatusTotalsByItemForRender($id,'platform',$filters,$opt);
		return $renderObj;
	}



	/**
	 *
	 * If no build set providede, ONLY ACTIVE BUILDS will be considered
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 *
	 */
	function getExecCountersByPriorityExecStatus($id, $filters=null, $opt=null)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
		$safe_id = intval($id);
		list($my,$builds,$sqlStm) = $this->helperGetExecCounters($safe_id, $filters, $opt);
	
		
		// This subquery is BETTER than the VIEW, need to understand why
		// LE: Latest Execution On Whole Test Plan => Ignore BUILD  and PLATFORM
		$sqlLE = $sqlStm['LE'];

		$sqlUnionA	=	"/* {$debugMsg} sqlUnionA - executions */" . 
						" SELECT (TPTCV.urgency * TCV.importance) AS urg_imp, " .
						" COALESCE(E.status,'{$this->notRunStatusCode}') AS status, " .
						" TPTCV.tcversion_id " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .
	
						$sqlStm['getAssignedFeatures'] .

						" /* Get importance  */ ".
						" JOIN {$this->tables['tcversions']} TCV " .
						" ON TCV.id = TPTCV.tcversion_id " .
	
						" /* GO FOR Absolute LATEST exec ID IGNORE BUILD AND Platform */ " .
						" JOIN ({$sqlLE}) AS LE " .
						" ON  LE.testplan_id = TPTCV.testplan_id " .
						" AND LE.tcversion_id = TPTCV.tcversion_id " .
						" AND LE.testplan_id = " . $safe_id .
	
						" /* Get execution statuses that CAN BE WRITTEN TO DB */ " .
						" JOIN {$this->tables['executions']} E " .
						" ON  E.id = LE.id " .
						
						// Without this we get duplicates ??
						$builds->joinAdd .

						" /* FILTER BUILD Set on target test plan */ " .
						" WHERE TPTCV.testplan_id=" . $safe_id . 
						$build->whereAddExec;
						

		$sqlUnionB	=	"/* {$debugMsg} sqlUnionB - NOT RUN */" . 
						" SELECT (TPTCV.urgency * TCV.importance) AS urg_imp, " .
						" COALESCE(E.status,'{$this->notRunStatusCode}') AS status, " .
						" TPTCV.tcversion_id " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .
						
						$sql['getAssignedFeatures'] .

						" /* Get importance  */ ".
						" JOIN {$this->tables['tcversions']} TCV " .
						" ON TCV.id = TPTCV.tcversion_id " .
	
						" /* Get REALLY NOT RUN => BOTH LE.id AND E.id ON LEFT OUTER see WHERE  */ " .
						" LEFT OUTER JOIN ({$sqlLE}) AS LE " .
						" ON  LE.testplan_id = TPTCV.testplan_id " .
						" AND LE.tcversion_id = TPTCV.tcversion_id " .
						" AND LE.testplan_id = " . intval($id) .
						" LEFT OUTER JOIN {$this->tables['executions']} E " .
						" ON  E.tcversion_id = TPTCV.tcversion_id " .
						" AND E.testplan_id = TPTCV.testplan_id " .
						" AND E.platform_id = TPTCV.platform_id " .
						$builds->joinAdd .

						" /* FILTER BUILDS in set on target test plan */ " .
						" WHERE TPTCV.testplan_id=" . $safe_id . 
						$builds->whereAddNotRun .
	
						" /* Get REALLY NOT RUN => BOTH LE.id AND E.id NULL  */ " .
						" AND E.id IS NULL AND LE.id IS NULL";


		// ATTENTION:
		// Each piece of UNION has 3 fields: urg_imp,status, TPTCV.tcversion_id 		
		// There is no way we can get more that ONE record with same TUPLE
		// on sqlUionA or sqlUnionB.
		//
		$sql =	" /* {$debugMsg} UNION WITHOUT ALL => DISCARD Duplicates */" .
				" SELECT count(0) as exec_qty, urg_imp,status " .
				" FROM ($sqlUnionA UNION $sqlUnionB ) AS SU " .
				" GROUP BY urg_imp,status ";
		// echo 'QD - <br>' . __FUNCTION__ . $sql . '<br>';
        $rs = $this->db->get_recordset($sql);
	

		// Now we need to get priority LEVEL from (urgency * importance)
		$out = array();
		$totals = array();
		if( !is_null($rs) )
		{
			$priorityCfg = config_get('urgencyImportance');
			$loop2do = count($rs);
			for($jdx=0; $jdx < $loop2do; $jdx++)
			{
				if ($rs[$jdx]['urg_imp'] >= $priorityCfg->threshold['high']) 
				{            
					$rs[$jdx]['priority_level'] = HIGH;
	                $hitOn = HIGH;
				} 
				else if( $rs[$jdx]['urg_imp'] < $priorityCfg->threshold['low']) 
				{
					$rs[$jdx]['priority_level'] = LOW;
	                $hitOn = LOW;
				}        
				else
				{
					$rs[$jdx]['priority_level'] = MEDIUM;
	                $hitOn = MEDIUM;
				}
                                      
                                                     
				// to improve readability                                                     	
				$status = $rs[$jdx]['status'];
				if( !isset($out[$hitOn][$status]) )
				{
					$out[$hitOn][$status] = $rs[$jdx];
				}
				else
				{
					$out[$hitOn][$status]['exec_qty'] += $rs[$jdx]['exec_qty'];
				}
				
				if( !isset($totals[$hitOn]) )
				{
					$totals[$hitOn] = array('priority_level' => $hitOn, 'qty' => 0);
				}
				$totals[$hitOn]['qty'] += $rs[$jdx]['exec_qty'];
			}
			$exec['with_tester'] = $out;
			$out = null; 
		}
		
		$this->helperCompleteStatusDomain($exec,'priority_level');
		$exec['total'] = $totals;

		$levels = config_get('urgency');
		foreach($levels['code_label'] as $lc => $lbl)
		{
			$exec['priority_levels'][$lc] = lang_get($lbl);
		}

		return $exec;
	}



	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120429 - franciscom - 
	 */
	function getStatusTotalsByPriorityForRender($id,$filters=null,$opt=null)
	{
		$renderObj = $this->getStatusTotalsByItemForRender($id,'priority_level',$filters,$opt);
		return $renderObj;
	}


	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120430 - franciscom - 
	 */
	function getExecCountersByBuildUAExecStatus($id, $opt=null)
	{
		//echo 'QD - <b><br>' . __FUNCTION__ . '</b><br>';
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;

		$my['opt'] = array('getUnassigned' => false);
		$my['opt'] = array_merge($my['opt'], (array)$opt);
		
		$activeBuilds = array_keys($ab=$this->get_builds($id,testplan::ACTIVE_BUILDS));
		$buildsInClause = implode(",",$activeBuilds);
		$execCode = intval($this->assignment_types['testcase_execution']['id']);
		
		new dBug($ab);
		
		
		
		// This subquery is BETTER than the VIEW, need to understand why
		// Last Executions By Build (LEBB)
		$sqlLEBB = 	" SELECT EE.tcversion_id,EE.testplan_id,EE.build_id,MAX(EE.id) AS id " .
				  	" FROM {$this->tables['executions']} EE " . 
				   	" WHERE EE.testplan_id=" . intval($id) . 
					" AND EE.build_id IN ({$buildsInClause}) " .
				   	" GROUP BY EE.tcversion_id,EE.testplan_id,EE.build_id ";
		
		
		// Common sentece - reusable
		$sqlExec = 	"/* {$debugMsg} */" . 
					" SELECT UA.user_id,UA.build_id,COALESCE(E.status,'{$this->notRunStatusCode}') AS status,".
					" count(0) AS exec_qty " .

					" /* Get feature id with Tester Assignment */ " .
					" FROM {$this->tables['testplan_tcversions']} TPTCV " .

					" /*LEFTPLACEHOLDER*/ JOIN {$this->tables['user_assignments']} UA " .
					" ON UA.feature_id = TPTCV.id " .
					" AND UA.build_id IN ({$buildsInClause}) AND UA.type = {$execCode} " .

					" /* GO FOR Absolute LATEST exec ID by BUILD IGNORE  Platform */ " .
					" LEFT OUTER JOIN ({$sqlLEBB}) AS LEBB " .
					" ON  LEBB.testplan_id = TPTCV.testplan_id " .
					" AND LEBB.tcversion_id = TPTCV.tcversion_id " .
					" AND LEBB.testplan_id = " . intval($id) .

					" /* Get execution status INCLUDING NOT RUN */ " .
					" LEFT OUTER JOIN {$this->tables['executions']} E " .
					" ON  E.id = LEBB.id " .
					" AND E.build_id = LEBB.build_id " .
					" AND E.build_id IN ({$buildsInClause}) ";
				
				
		// get all execution status from DB Only for test cases with tester assigned			
		$sql = 	$sqlExec .		
				" /* FILTER ONLY ACTIVE BUILDS on target test plan */ " .
				" WHERE TPTCV.testplan_id=" . intval($id) . 
				" AND UA.build_id IN ({$buildsInClause}) " .
				" GROUP BY user_id,build_id,status ";
	
		// 
		//echo 'QD - <br><b>' . __FUNCTION__ . '</b><br>'; 
		//echo 'QD - ' . $sql . '<br>';
		$keyColumns = array('build_id','user_id','status');
        $exec['with_tester'] = (array)$this->db->fetchRowsIntoMap3l($sql,$keyColumns);              

		$totals = array();
		foreach($exec as &$topLevelElem)
		{                             
			$topLevelItemSet = array_keys($topLevelElem);
			foreach($topLevelItemSet as $topLevelItemID)
			{
				$itemSet = array_keys($topLevelElem[$topLevelItemID]);
				foreach($itemSet as $itemID)
				{
					$elem = &$topLevelElem[$topLevelItemID];
					foreach($this->statusCode as $verbose => $code)
					{
						if(!isset($elem[$itemID][$code]))
						{
							$elem[$itemID][$code] = array('build_id' => $topLevelItemID, 'user_id' => $itemID,
														  'status' => $code, 'exec_qty' => 0);			
						}												   

						if( !isset($totals[$topLevelItemID][$itemID]) )
						{
							$totals[$topLevelItemID][$itemID] = array('build_id' => $topLevelItemID, 
							 										  'user_id' => $itemID, 'qty' => 0);
						}
						$totals[$topLevelItemID][$itemID]['qty'] += $elem[$itemID][$code]['exec_qty'];
					}
				}
			}
		}
		$exec['total'] = $totals;

		return $exec;
	}



	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120430 - franciscom - 
	 */
	function getStatusTotalsByBuildUAForRender($id)
	{

	   	$renderObj = null;
		$code_verbose = $this->getStatusForReports();
	    $labels = $this->resultsCfg['status_label'];
		$metrics = $this->getExecCountersByBuildUAExecStatus($id);
		
	   	if( !is_null($metrics) )
	   	{
	   		$renderObj = new stdClass();
			$topItemSet = array_keys($metrics['with_tester']);
			$renderObj->info = array();	
			$out = &$renderObj->info;

			$topElem = &$metrics['with_tester'];
			foreach($topItemSet as $topItemID)
			{
				$itemSet = array_keys($topElem[$topItemID]);
				foreach($itemSet as $itemID)
				{
					$elem = &$topElem[$topItemID][$itemID];

					$out[$topItemID][$itemID]['total'] = $metrics['total'][$topItemID][$itemID]['qty'];
					$progress = 0; 
					foreach($code_verbose as $statusCode => $statusVerbose)
					{
						$out[$topItemID][$itemID][$statusVerbose]['count'] = $elem[$statusCode]['exec_qty'];
						$pc = ($elem[$statusCode]['exec_qty'] / $out[$topItemID][$itemID]['total']) * 100;
						$out[$topItemID][$itemID][$statusVerbose]['percentage'] = number_format($pc, 1);

						if($statusVerbose != 'not_run')
						{
							$progress += $elem[$statusCode]['exec_qty'];
						}
					}	
					$progress = ($progress / $out[$topItemID][$itemID]['total']) * 100;
					$out[$topItemID][$itemID]['progress'] = number_format($progress,1); 
				}
			}
		}
		return $renderObj;
	}



	/**
	 *
	 * @internal revisions
	 *
	 * @since 1.9.4
	 * 20120429 - franciscom - 
	 */
	function getStatusTotalsByItemForRender($id,$itemType,$filters=null,$opt=null)
	{
	   	$renderObj = null;
		$code_verbose = $this->getStatusForReports();
	    $labels = $this->resultsCfg['status_label'];

		switch($itemType)
		{	
			case 'keyword':    
				$metrics = $this->getExecCountersByKeywordExecStatus($id,$filters,$opt);
				$setKey = 'keywords';
			break;

			case 'platform':    
				$metrics = $this->getExecCountersByPlatformExecStatus($id,$filters,$opt);
				$setKey = 'platforms';
			break;
			
			case 'priority_level':    
				$metrics = $this->getExecCountersByPriorityExecStatus($id,$filters,$opt);
				$setKey = 'priority_levels';
			break;
		}


	   	if( !is_null($metrics) )
	   	{
	   		$renderObj = new stdClass();
			$itemList = array_keys($metrics[$setKey]);
			$renderObj->info = array();	
		    foreach($itemList as $itemID)
		    {
		    	if( isset($metrics['with_tester'][$itemID]) )
		    	{
					$totalRun = 0;
		    		$renderObj->info[$itemID]['type'] = $itemType;
		    		$renderObj->info[$itemID]['name'] = $metrics[$setKey][$itemID]; 	
		    		$renderObj->info[$itemID]['total_tc'] = $metrics['total'][$itemID]['qty']; 	
					$renderObj->info[$itemID]['details'] = array();
					
					$rf = &$renderObj->info[$itemID]['details'];
					foreach($code_verbose as $statusCode => $statusVerbose)
					{
						$rf[$statusVerbose] = array('qty' => 0, 'percentage' => 0);
						$rf[$statusVerbose]['qty'] = $metrics['with_tester'][$itemID][$statusCode]['exec_qty']; 	
						
						if( $renderObj->info[$itemID]['total_tc'] > 0 ) 
						{
							$rf[$statusVerbose]['percentage'] = number_format(100 * 
																			  ($rf[$statusVerbose]['qty'] / 
																 			   $renderObj->info[$itemID]['total_tc']),1);
						}
						$totalRun += $statusVerbose == 'not_run' ? 0 : $rf[$statusVerbose]['qty'];
					}
					$renderObj->info[$itemID]['percentage_completed'] =  number_format(100 * 
																						($totalRun / 
																						 $renderObj->info[$itemID]['total_tc']),1);
		    	}
		    }
		   	
		    foreach($code_verbose as $status_verbose)
		    {
		    	$l18n_label = isset($labels[$status_verbose]) ? lang_get($labels[$status_verbose]) : 
		                      lang_get($status_verbose); 
		    
		    	$renderObj->colDefinition[$status_verbose]['qty'] = $l18n_label;
		    	$renderObj->colDefinition[$status_verbose]['percentage'] = '[%]';
		    }
	
		}
		return $renderObj;
	}


	/** 
	 *    
	 *    
	 *    
	 *    
	 */    
	function getExecCountersByTestSuiteExecStatus($id, $filters=null, $opt=null)
	{
		$debugMsg = 'Class:' . __CLASS__ . ' - Method: ' . __FUNCTION__;
		$safe_id = intval($id);
		list($my,$builds,$sqlStm) = $this->helperGetExecCounters($id, $filters, $opt);

		// Latest Execution Ignoring Build and Platform
		$sqlLE = $sqlStm['LE'];

		$sqlUnionAT	=	"/* {$debugMsg} sqlUnionAT - executions */" . 
						" SELECT NHTC.parent_id AS tsuite_id, " .
						" COALESCE(E.status,'{$this->notRunStatusCode}') AS status " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .

						$sql['getAssignedFeatures'] .
						
						" /* GO FOR Absolute LATEST exec ID IGNORE BUILD AND PLATFORM */ " .
						" JOIN ({$sqlLE}) AS LE " .
						" ON  LE.testplan_id = TPTCV.testplan_id " .
						" AND LE.tcversion_id = TPTCV.tcversion_id " .
						" AND LE.testplan_id = " . $safe_id .

						" /* Get execution status WRITTEN on DB */ " .
						" JOIN {$this->tables['executions']} E " .
						" ON  E.id = LE.id " .

						" /* Get Test Case info from Test Case Version */ " .
						" JOIN {$this->tables['nodes_hierarchy']} NHTCV " .
						" ON  NHTCV.id = TPTCV.tcversion_id " .

						" /* Get Test Suite info from Test Case  */ " .
						" JOIN {$this->tables['nodes_hierarchy']} NHTC " .
						" ON  NHTC.id = NHTCV.parent_id " .
			
						" WHERE TPTCV.testplan_id=" . $safe_id .
						$builds->whereAddExec;
						

		//echo 'QD - <br>' . $sqlUnionAT . '<br>';
		//echo 'QD - ' . $sql . '<br>';

		$sqlUnionBT	=	"/* {$debugMsg} sqlUnionBK - NOT RUN */" . 
						" SELECT NHTC.parent_id AS tsuite_id, " .
						" COALESCE(E.status,'{$this->notRunStatusCode}') AS status " .
						" FROM {$this->tables['testplan_tcversions']} TPTCV " .
						
						$sql['getAssignedFeatures'] .

						" /* Get REALLY NOT RUN => BOTH LE.id AND E.id ON LEFT OUTER see WHERE  */ " .
						" LEFT OUTER JOIN ({$sqlLE}) AS LE " .
						" ON  LE.testplan_id = TPTCV.testplan_id " .
						" AND LE.tcversion_id = TPTCV.tcversion_id " .
						" AND LE.testplan_id = " . $safe_id .
						" LEFT OUTER JOIN {$this->tables['executions']} E " .
						" ON  E.tcversion_id = TPTCV.tcversion_id " .
						" AND E.testplan_id = TPTCV.testplan_id " .
						" AND E.platform_id = TPTCV.platform_id " .

						$builds->joinAdd .

						" /* Get Test Case info from Test Case Version */ " .
						" JOIN {$this->tables['nodes_hierarchy']} NHTCV " .
						" ON  NHTCV.id = TPTCV.tcversion_id " .

						" /* Get Test Suite info from Test Case  */ " .
						" JOIN {$this->tables['nodes_hierarchy']} NHTC " .
						" ON  NHTC.id = NHTCV.parent_id " .

						" /* FILTER BUILDS in set on target test plan (not alway can be applied) */ " .
						" WHERE TPTCV.testplan_id=" . $safe_id . 
						$builds->whereAddNotRun .
	
						" /* Get REALLY NOT RUN => BOTH LE.id AND E.id NULL  */ " .
						" AND E.id IS NULL AND LE.id IS NULL";

		//echo 'QD - <br>' . $sqlUnionBT . '<br>';
	
		
		
		$sql =	" /* {$debugMsg} UNION ALL => DO NOT DISCARD Duplicates */" .
				" SELECT tsuite_id,status, count(0) AS exec_qty " .
				" FROM ($sqlUnionAT UNION ALL $sqlUnionBT ) AS SQT " .
				" GROUP BY tsuite_id,status ";

		// 
		//echo 'QD -<br><b>' . __FUNCTION__ . '</b><br>'; 
		echo 'QD - ' . $sql . '<br>';
        $exec['with_tester'] = (array)$this->db->fetchMapRowsIntoMap($sql,'tsuite_id','status');              

		// now we need to complete status domain
		$this->helperCompleteStatusDomain($exec,'tsuite_id');
	

		return $exec;
	}
	
	
		
	/** 
	 *    
	 *    
	 *    
	 *    
	 */    
	function helperGetExecCounters($id, $filters, $opt)
	{
		$sql = array();
		$my = array();
		$my['opt'] = array('getOnlyAssigned' => false, 'tprojectID' => 0);
		$my['opt'] = array_merge($my['opt'], (array)$opt);
		
		$my['filters'] = array('buildSet' => null);
		$my['filters'] = array_merge($my['filters'], (array)$filters);
		
		// Build Info
		$bi = new stdClass();
		$bi->idSet = $my['filters']['buildSet']; 
		$bi->inClause = '';
		$bi->infoSet = null;
		if( is_null($bi->idSet) )
		{
			$bi->idSet = array_keys($bi->infoSet=$this->get_builds($id,testplan::ACTIVE_BUILDS));
		}
		$bi->inClause = implode(",",$bi->idSet);


		if( $my['opt']['getOnlyAssigned'] )
		{
			$sql['getAssignedFeatures']	 =	" /* Get feature id with Tester Assignment */ " .
											" JOIN {$this->tables['user_assignments']} UA " .
											" ON UA.feature_id = TPTCV.id " .
											" AND UA.build_id IN ({$bi->inClause}) AND UA.type = {$this->execTaskCode} ";
			$bi->source = "UA";
			$bi->joinAdd = " AND E.build_id = UA.build_id ";
			$bi->whereAddExec = " AND {$bi->source}.build_id IN ({$bi->inClause}) "; 
			$bi->whereAddNotRun = $bi->whereAddExec; 

		}						
		else
		{
			$sql['getAssignedFeatures'] = '';
			$bi->source = "E";
			$bi->joinAdd = "";
			
			// Why ?
			// If I'm consider test cases WITH and WITHOUT Tester assignment,
			// I will have no place to go to filter for builds.
			// Well at least when trying to get EXECUTED test case, I will be able
			// to apply filter on Executions table.
			// Why then I choose to have this blank ANYWAY ?
			// Because I will get filtering on Build set through 
			// the Latest Execution queries (see below sql['LE'], sql['LEBP'].
			// 
			// Anyway we need to backup all these thoughts with a long, long test run
			// on test link itself.
			$bi->whereAddExec = " AND {$bi->source}.build_id IN ({$bi->inClause}) "; 
			$bi->whereAddNotRun = ""; 
		}               

		$sql['LE'] = " SELECT EE.tcversion_id,EE.testplan_id,MAX(EE.id) AS id " .
				  	 " FROM {$this->tables['executions']} EE " . 
				   	 " WHERE EE.testplan_id=" . $id . 
					 " AND EE.build_id IN ({$bi->inClause}) " .
				   	 " GROUP BY EE.tcversion_id,EE.testplan_id ";

		$sql['LEBP'] = 	" SELECT EE.tcversion_id,EE.testplan_id,EE.platform_id,MAX(EE.id) AS id " .
				  		" FROM {$this->tables['executions']} EE " . 
				   		" WHERE EE.testplan_id=" . $id . 
						" AND EE.build_id IN ({$bi->inClause}) " .
				   		" GROUP BY EE.tcversion_id,EE.testplan_id,EE.platform_id ";
	
		return array($my,$bi,$sql);
	}	



	/** 
	 *    
	 *    
	 *    
	 *    
	 */    
	function helperCompleteStatusDomain(&$out,$key)
	{                       
		$totalByItemID = array();
		
		// refence is critic	
		foreach($out as &$elem)
		{                             
			$itemSet = array_keys($elem);
			foreach($itemSet as $itemID)
			{             
				$totalByItemID[$itemID]['qty'] = 0;
				foreach($this->statusCode as $verbose => $code)
				{
					if(!isset($elem[$itemID][$code]))
					{
						$elem[$itemID][$code] = array($key => $itemID,'status' => $code, 'exec_qty' => 0);			
					}												   
		            $totalByItemID[$itemID]['qty'] += $elem[$itemID][$code]['exec_qty'];
				}
			}
		}
		$out['total'] = $totalByItemID;
	}
	
}
?>