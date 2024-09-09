<?php
// index.php
global $link, $org_site;
header('Content-Type: application/json');
function remove_session()
{
    $_SESSION = array();
    if ( isset($_COOKIE[session_name()]) )
        setcookie(session_name(), '', time()-42000, '/');
    session_destroy();
}


/**
 * Parses the given radial grid identifier and returns the corresponding name or index.
 *
 * @param mixed $radGrid The radial grid identifier, which can be a string or numeric value.
 * @return int|string Returns the corresponding name or index of the radial grid, or -1 if the identifier is not known.
 */
function parseRadialGrid($radGrid)
{
    $knownGrids = [
        '0' => 'ASTFEM',
        '1' => 'Claverie',
        '2' => 'Moving Hat',
        '3' => 'ASTFVM',
        'ASTFEM' => 0,
        'Claverie' => 1,
        'Moving Hat' => 2,
        'ASTFVM' => 3,
    ];

    return $knownGrids[(string)$radGrid] ??-1;
}


/**
 * Parses the given time grid identifier and returns the corresponding name or index.
 *
 * @param mixed $time_grid The time grid identifier, which can be a string or numeric value.
 * @return int|string Returns the corresponding name or index of the time grid, or -1 if the identifier is not known.
 */
function parseTimeGrid($time_grid)
{
    $knownGrids = [
        '0' => 'AST',
        '1' => 'Constant',
        'AST' => 0,
        'Constant' => 1
    ];

    return $knownGrids[(string)$time_grid] ??-1;
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}
include_once 'checkinstance.php';
include_once 'config.php';
global $full_path;
set_include_path(get_include_path(). PATH_SEPARATOR . $full_path);
include_once 'db.php';
include_once 'lib/utility.php';
include_once 'Submitter.php';
global $dbname;
$BASE_URI =  $dbname . "/api/";

$endpoints = array();
$requestData = array();
$params = array();
$parsedURI = parse_url($_SERVER["REQUEST_URI"]);
$path = $parsedURI['path'];
$endpoint = str_replace($BASE_URI, '', $path);
$method = $_SERVER['REQUEST_METHOD'];
parse_str($parsedURI['query']??'', $params);
$endpointName = $endpoint ?? '';

// Authenticate user by request header values
$headers = apache_request_headers();
$email = $headers['Us-Email'] ?? '';
$password = $headers['Us-Password'] ?? '';

// Check if email and password are provided
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit();
}
// check if email is valid
if (!emailsyntax_is_valid($email)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address']);
    exit();
}

// Check if user exists
// Convert password to md5 hash

// Find the id of the record with the same e-mail address:
$query  = $link->prepare("SELECT * FROM people WHERE email= ?");
$query->bind_param("s", $email);
$query->execute();
$result = $query->get_result()
or die( "Query failed : $query->error<br />\n" . mysqli_error($link) );
$result_str = print_r($result, true);

$row    = mysqli_fetch_assoc($result);
$count  = $result->num_rows;
$USER_DATA = array();
// Check if user exists
if ($count == 0) {
    remove_session();
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email and password combination']);
    exit();
}
elseif ($count == 1) {
    $personID = $row['personID'] ?? 0;
    $fname = $row['fname'] ?? '';
    $lname = $row['lname'] ?? '';
    $phone = $row['phone'] ?? '';
    $email = $row['email'] ?? '';
    $userlevel = $row['userlevel'] ?? 1;
    $advancelevel = $row['advancelevel'] ?? 0;
    $clusterAuthorizations = $row['clusterAuthorizations'] ?? '';
    $activated = $row['activated'] ?? 0;
    $dbname = $dbname ?? '';
    $personGUID = $row['personGUID'] ?? '';
    $USER_DATA['id']           = $personID;
    $USER_DATA['loginID']      = $personID;  // This never changes, even if working on behalf of another
    $USER_DATA['firstname']    = $fname;
    $USER_DATA['lastname']     = $lname;
    $USER_DATA['guid']         = $personGUID;
    $USER_DATA['first_name']    = $fname;
    $USER_DATA['last_name']     = $lname;
    $USER_DATA['phone']        = $phone;
    $USER_DATA['email']        = $email;
    $USER_DATA['submitter_email'] = $email;
    $USER_DATA['userlevel']    = $userlevel;
    $USER_DATA['instance']     = $dbname;
    $USER_DATA['user_id' ]     = $fname . "_" . $lname . "_" . $personGUID;
    $USER_DATA['advancelevel'] = $advancelevel;
    $USER_DATA['activated'] = $activated;

    // Set cluster authorizations
    $clusterAuth = array();
    $clusterAuth = explode(":", $clusterAuthorizations );
    $USER_DATA['clusterAuth'] = $clusterAuth;

    // Set GateWay host ID
    $gwhostids = array();
    $gwhostids[ 'uslims3.uthscsa.edu' ]       = 'uslims3.uthscsa.edu_e47e8a2d-9cb7-4489-a84d-38636fb3ed01';
    $gwhostids[ 'uslims3.aucsolutions.com' ]  = 'uslims3.aucsolutions.com_91754ea7-e3be-4895-b501-05f0ca2c0ccd';
    $gwhostids[ 'uslims3.fz-juelich.de' ]     = 'uslims3.fz-juelich.de_283650c2-8815-43b2-8150-907feb6935bb';
    $gwhostids[ 'uslims3.latrobe.edu.au' ]    = 'uslims3.latrobe.edu.au_dea05b5c-5596-49b9-bd10-b0c593713be1';
    $gwhostids[ 'uslims3.mbu.iisc.ernet.in' ] = 'uslims3.mbu.iisc.ernet.in_0ef689dc-5b41-438a-b06d-e2c19b74a920';
    $gwhostids[ 'gw143.iu.xsede.org']         = 'gw143.iu.xsede.org_3bce3fc7-25ed-41eb-97fb-c0930569ceeb';
    $gwhostids[ 'vm1584.kaj.pouta.csc.fi' ]   = 'vm1584.kaj.pouta.csc.fi_35eab34c-7e76-4b3f-a943-c145fde85f36';
    $gwhostids[ 'uslims.uleth.ca' ]           = 'uslims.uleth.ca_82aea4e7-f4a4-4deb-93ac-47e3ad32c868';
    $gwhostids[ 'demeler6.uleth.ca' ]         = 'demeler6.uleth.ca_7b30612e-ab07-4729-81f7-75af7f674e1f';
    $gwhost    = dirname( $org_site );
    if ( preg_match( "/\/uslims3/", $gwhost ) )
        $gwhost    = dirname( $gwhost );
    $gwhostid  = $gwhost;
    if ( isset( $gwhostids[ $gwhost ] ) )
        $gwhostid  = $gwhostids[ $gwhost ];

    $USER_DATA[ 'gwhostid' ] = $gwhostid;
    if ( md5($password) != $row['password'] )
    {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email and password combination']);
        exit();
    }

    if ( $row["activated"] != 1 )
    {
        http_response_code(401);
        echo json_encode(['error' => 'Account is not activated']);
        exit();
    }
}
elseif ($count > 1) {
    http_response_code(500);
    echo json_encode(['error' => 'Multiple users found with the same email address']);
    exit();
}
if ($USER_DATA['userlevel'] == 0 || $USER_DATA['activated'] != 1 ) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit();
}
$query->free_result();
$query->close();
// User is authenticated
// Get the request method

if (!str_starts_with($endpointName, '/'))
{
    $endpointName = '/' . $endpointName;
}
switch ($method) {
    case 'GET':
        // Get all experiments
        if ($endpointName === '/experiments') {
            // Get all experiments this user is associated with, ignoring US_ADMIN or US_SUPER permissions to everything

            $query_str  = "SELECT distinct 
    e.experimentID, 
    e.dateUpdated as udate, 
    e.runID, 
    e.projectID,
    e.label,
    e.operatorID FROM experiment e";
            if ($USER_DATA['userlevel'] < 3) {
                $query_str .= " JOIN experimentPerson ep ON e.experimentID = ep.experimentID WHERE ep.personID = ?";
                $query = $link->prepare($query_str);
                $query->bind_param("i", $USER_DATA['id']);
            }
            else {
                $query = $link->prepare($query_str);
            }

            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            $experiments = [];
            while (list($experimentID, $udate, $runID, $projectID, $label, $operatorID) = mysqli_fetch_array($result)) {
                $experiment = array(
                    'experimentID' => $experimentID,
                    'lastUpdated' => $udate,
                    'runID' => $runID,
                    'projectID' => $projectID,
                    'label' => $label,
                    'operatorID' => $operatorID
                );
                // get all associated persons
                $query2  = $link->prepare("SELECT personID FROM experimentPerson WHERE experimentID = ?");
                $query2->bind_param("i", $experimentID);
                $query2->execute();
                $result2 = $query2->get_result();
                $persons = [];
                while ($row2 = mysqli_fetch_array($result2)) {
                    $persons[] = $row2['personID'];
                }
                $experiment['persons'] = $persons;
                $query2->free_result();
                $query2->close();
                $experiments[] = $experiment;
            }
            $query->free_result();
            $query->close();
            echo json_encode($experiments);
        }
        // Get experiment details
        elseif (preg_match('/^\/experiments\/(\d+)$/', $endpointName, $matches)) {
            // Get a specific experiment by ID
            $experimentID = $matches[1];
            $query_str  ="SELECT distinct e.experimentID,
            e.dateUpdated as udate, projectID, runID, label, instrumentID, operatorID, rotorID, rotorCalibrationID,
             experimentGUID, type, runType, dateBegin, runTemp, comment
             FROM experiment e join experimentPerson ep on ep.experimentID = e.experimentID
             WHERE e.experimentID = ?";
            if ($USER_DATA['userlevel'] < 3) {
                $query_str .= " AND ep.personID = ?";
                $query = $link->prepare($query_str);
                $query->bind_param("ii", $USER_DATA['id'],$experimentID);
            }
            else
            {
                $query = $link->prepare($query_str);
                $query->bind_param("i", $experimentID);
            }
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Experiment not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple experiments found with the same ID']);
                exit();
            }
            $experiment = array();
            while (list($expID, $udate, $projectID, $runID, $label, $instrumentID, $operatorID, $rotorID, $rotorCalibrationID, $experimentGUID, $type, $runType, $dateBegin, $runTemp, $comment) = mysqli_fetch_array($result)) {
                $experiment = array(
                    'experimentID' => $expID,
                    'lastUpdated' => $udate,
                    'projectID' => $projectID,
                    'runID' => $runID,
                    'label' => $label,
                    'instrumentID' => $instrumentID,
                    'operatorID' => $operatorID,
                    'rotorID' => $rotorID,
                    'rotorCalibrationID' => $rotorCalibrationID,
                    'experimentGUID' => $experimentGUID,
                    'experimentType' => $type,
                    'runType' => $runType,
                    'dateBegin' => $dateBegin,
                    'runTemp' => $runTemp,
                    'comment' => $comment,
                );
            }
            $query->free_result();
            $query->close();
            // Get the project details
            $query  = $link->prepare("SELECT * FROM project WHERE projectID = ?");
            $query->bind_param("i", $experiment['projectID']);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                $experiment['projects'] = [];
            }
            else {
                $projects = [];
                while ($row = mysqli_fetch_array($result)) {
                    $projects[] = array(
                        'projectID' => $row['projectID'],
                        'description' => $row['description'],
                        'status' => $row['status'],
                    );
                }
                $experiment['projects'] = $projects;
            }
            $query->free_result();
            $query->close();
            // get all associated persons
            $query2  = $link->prepare("SELECT personID FROM experimentPerson WHERE experimentID = ?");
            $query2->bind_param("i", $experimentID);
            $query2->execute();
            $result2 = $query2->get_result();
            $persons = [];
            while ($row2 = mysqli_fetch_array($result2)) {
                $persons[] = $row2['personID'];
            }
            $experiment['persons'] = $persons;
            $query2->free_result();
            $query2->close();
            // get connected raw data
            $query  = $link->prepare("SELECT r.rawDataID, r.label, r.filename, 
            r.comment, TRIM(TRAILING CHAR(0x00) FROM CONVERT (substr(data from 27 for 240) USING utf8)) as description
            from rawData r where r.experimentID = ? ORDER BY r.filename");
            $query->bind_param("i", $experimentID);
            $query->execute();
            $result = $query->get_result();
            $experiment['rawdata'] = [];
            while ($row = mysqli_fetch_array($result)) {
                $raw_dataset = array(
                    'rawDataID' => $row['rawDataID'],
                    'label' => $row['label'],
                    'filename' => $row['filename'],
                    'comment' => $row['comment'],
                    'description' => $row['description']
                );
                $experiment['rawdata'][] = $raw_dataset;
            }
            $query->free_result();
            $query->close();

            echo json_encode($experiment);
        }
        // GET HPCAnalysisRequest for experiment
        elseif (preg_match('/^\/experiments\/(\d+)\/hpcrequests$/', $endpointName, $matches)) {
            // Get a specific experiment by ID
            $experimentID = $matches[1];
            $query_str  = "SELECT distinct e.experimentID,
            e.dateUpdated as udate, projectID, runID, label, instrumentID, operatorID, rotorID, rotorCalibrationID,
             experimentGUID, type, runType, dateBegin, runTemp, comment
             FROM experiment e join experimentPerson ep on ep.experimentID = e.experimentID  
             WHERE e.experimentID = ?";
            if ($USER_DATA['userlevel'] < 3) {
                $query_str .= " AND ep.personID = ?";
                $query = $link->prepare($query_str);
                $query->bind_param("ii", $USER_DATA['id'],$experimentID);
            }
            else
            {
                $query = $link->prepare($query_str);
                $query->bind_param("i", $experimentID);
            }
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Experiment not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple experiments found with the same ID']);
                exit();
            }
            $experiment = array();
            while (list($expID, $udate, $projectID, $runID, $label, $instrumentID, $operatorID, $rotorID, $rotorCalibrationID, $experimentGUID, $type, $runType, $dateBegin, $runTemp, $comment) = mysqli_fetch_array($result)) {
                $experiment = array(
                    'experimentID' => $expID,
                    'lastUpdated' => $udate,
                    'projectID' => $projectID,
                    'runID' => $runID,
                    'label' => $label,
                    'instrumentID' => $instrumentID,
                    'operatorID' => $operatorID,
                    'rotorID' => $rotorID,
                    'rotorCalibrationID' => $rotorCalibrationID,
                    'experimentGUID' => $experimentGUID,
                    'type' => $type,
                    'runType' => $runType,
                    'dateBegin' => $dateBegin,
                    'runTemp' => $runTemp,
                    'comment' => $comment
                );
            }
            $query->free_result();
            $query->close();
            // get connected HPCAnalysisRequest
            $query = $link->prepare("SELECT HPCAnalysisRequestID,
       investigatorGUID, submitterGUID FROM HPCAnalysisRequest WHERE experimentID = ?");
            $query->bind_param("i", $experimentID);
            $query->execute();
            $result = $query->get_result();
            $hpcrequests = [];
            while (list($HPCAnalysisRequestID, $investigatorGUID, $submitterGUID) = mysqli_fetch_array($result)) {
                $hpcrequests[] = array(
                    'HPCAnalysisRequestID' => $HPCAnalysisRequestID,
                    'investigatorGUID' => $investigatorGUID,
                    'submitterGUID' => $submitterGUID
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($hpcrequests);
        }
        // Search experiments
        elseif (str_starts_with($endpointName, '/experiments/search')) {
            // Filter experiments by query parameters
            $query_params = [$USER_DATA['id']];
            $query_params_type = 'i';
            $query  = "SELECT distinct
            e.experimentID, 
    e.dateUpdated as udate, 
    e.runID, 
    e.projectID,
    e.label FROM experiment e
            JOIN experimentPerson ep ON e.experimentID = ep.experimentID 
            JOIN project p ON e.projectID = p.projectID
                ";
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " WHERE ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            else {
                $query .= " WHERE 1 = 1";
            }
            if (isset($params['projectID']) && is_numeric($params['projectID'])) {
                $query .= " AND e.projectID = ?";
                $query_params[] = (int)$params['projectID'];
                $query_params_type .= 'i';
            }
            if (isset($params['runID'])) {
                $query .= " AND e.runID like ?";
                $query_params[] = '%' . $params['runID'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['label'])) {
                $query .= " AND e.label like ?";
                $query_params[] = '%' . $params['label'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['project'])) {
                $query .= " AND p.description like ?";
                $query_params[] = '%' . $params['project'] . '%';
                $query_params_type .= 's';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            $experiments = [];
            while (list($expID, $udate, $runID, $projectID, $label) = mysqli_fetch_array($result)) {
                $experiments[] = array(
                    'experimentID' => $expID,
                    'lastUpdated' => $udate,
                    'runID' => $runID,
                    'projectID' => $projectID,
                    'label' => $label
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($experiments);
        }
        // Get persons
        elseif ($endpointName === '/persons') {
            // Get all persons
            $query_str = 'SELECT personID, personGUID, fname, lname, email, username, activated, userlevel, 
       advancelevel, clusterAuthorizations FROM people';
            $query_params = [];
            $query_params_type = '';
            if ( $USER_DATA['userlevel'] < 3 ) {
                $query_str .= " WHERE personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type = 'i';
            }
            $query = $link->prepare($query_str);
            if (count($query_params) > 0)
		$query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            $persons = [];
            while ($row = mysqli_fetch_array($result)) {
                $persons[] = array(
                    'personID' => $row['personID'],
                    'personGUID' => $row['personGUID'],
                    'firstName' => $row['fname'],
                    'lastName' => $row['lname'],
                    'email' => $row['email'],
                    'username' => $row['username'],
                    'activated' => (bool)$row['activated'],
                    'userLevel' => $row['userlevel'],
                    'advancedLevel' => $row['advancelevel'],
                    'clusterAuthorizations' => $row['clusterAuthorizations']
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($persons);
        }
        // Get person details
        elseif ( preg_match('/^\/persons\/(\d+)$/', $endpointName, $matches) ) {
            // Get a specific person by ID
            $personID = (int)$matches[1];
            if ($USER_DATA['userlevel'] < 3 && $personID != $USER_DATA['id']) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            $query = $link->prepare("SELECT * FROM people WHERE personID = ?");
            $query->bind_param("i", $personID);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Person with personID '. $personID .' not found']);
                exit();
            }
            $user_data = array();
            while ($row = mysqli_fetch_array($result)) {
                $user_data['personID'] = $row['personID'];
                $user_data['personGUID'] = $row['personGUID'];
                $user_data['firstName'] = $row['fname'];
                $user_data['lastName'] = $row['lname'];
                $user_data['email'] = $row['email'];
                $user_data['username'] = $row['username'];
                if ( $row['activated'] == 1) {
                    $user_data['activated'] = true;
                }
                else {
                    $user_data['activated'] = false;
                }
                $user_data['userLevel'] = $row['userlevel'];
                if ( !$user_data['activated'] || $user_data['userLevel'] < 2 )
                {
                    $user_data['advancedLevel'] = 0;
                    $user_data['clusterAuthorizations'] = '';
                    $user_data['clusterNodes'] = [];
                    break;
                }

                $user_data['advancedLevel'] = $row['advancelevel'];

                $user_data['clusterAuthorizations'] = $row['clusterAuthorizations'];
                $clusterAuth = array();
                $clusterAuth = explode(":", $clusterAuthorizations );
                // construct available cluster nodes
                global $clusters;
                global $org_site;
                global $global_cluster_details;
                $clusterNodes = array();
                if ( $user_data['userLevel'] > 3 || count($clusterAuth) > 0 ) {
                    foreach ( $clusters as $cluster )
                    {
                        if (
                            in_array($cluster->short_name, $clusterAuth ) &&
                            array_key_exists($cluster->short_name, $global_cluster_details )
                        ) {
                            $p_cluster = array();
                            $clname = $cluster->name;
                            if ( preg_match( '/localhost/', $clname ) )
                            {  // Form local cluster name
                                $parts  = explode( "/", $org_site );
                                $lohost = $parts[ 0 ];
                                $clname = preg_replace( '/uslims3/', $cluster->short_name, $lohost );
                            }
                            $p_cluster['name'] = $cluster->name;
                            $p_cluster['explicit_name'] = $clname;
                            $p_cluster['short_name'] = $cluster->short_name;
                            $p_cluster['queue'] = $cluster->queue;
                            $clusterNodes[] = $p_cluster;
                        }
                    }
                }
                $user_data['clusterNodes'] = $clusterNodes;
            }
            $query->free_result();
            $query->close();
            echo json_encode($user_data);
        }
        // Get personal information
        elseif ($endpointName === '/persons/me') {
            $personID = (int)$USER_DATA['id'];
            $query = $link->prepare("SELECT * FROM people WHERE personID = ?");
            $query->bind_param("i", $personID);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            $user_data = array();
            while ($row = mysqli_fetch_array($result)) {
                $user_data['personID'] = $row['personID'];
                $user_data['personGUID'] = $row['personGUID'];
                $user_data['firstName'] = $row['fname'];
                $user_data['lastName'] = $row['lname'];
                $user_data['email'] = $row['email'];
                $user_data['username'] = $row['username'];
                if ( $row['activated'] == 1) {
                    $user_data['activated'] = true;
                }
                else {
                    $user_data['activated'] = false;
                }
                $user_data['userLevel'] = $row['userlevel'];
                if ( !$user_data['activated'] || $user_data['userLevel'] < 2 )
                {
                    $user_data['advancedLevel'] = 0;
                    $user_data['clusterAuthorizations'] = '';
                    $user_data['clusterNodes'] = [];
                    break;
                }

                $user_data['advancedLevel'] = $row['advancelevel'];

                $user_data['clusterAuthorizations'] = $row['clusterAuthorizations'];
                $clusterAuth = array();
                $clusterAuth = explode(":", $clusterAuthorizations );
                // construct available cluster nodes
                global $clusters;
                global $org_site;
                global $global_cluster_details;
                $clusterNodes = array();
                if ( $user_data['userLevel'] > 3 || count($clusterAuth) > 0 ) {
                    foreach ( $clusters as $cluster )
                    {
                        if (
                            in_array($cluster->short_name, $clusterAuth ) &&
                            array_key_exists($cluster->short_name, $global_cluster_details )
                        ) {
                            $p_cluster = array();
                            $clname = $cluster->name;
                            if ( preg_match( '/localhost/', $clname ) )
                            {  // Form local cluster name
                                $parts  = explode( "/", $org_site );
                                $lohost = $parts[ 0 ];
                                $clname = preg_replace( '/uslims3/', $cluster->short_name, $lohost );
                            }
                            $p_cluster['name'] = $cluster->name;
                            $p_cluster['explicit_name'] = $clname;
                            $p_cluster['short_name'] = $cluster->short_name;
                            $p_cluster['queue'] = $cluster->queue;
                            $clusterNodes[] = $p_cluster;
                        }
                    }
                }
                $user_data['clusterNodes'] = $clusterNodes;
            }
            $query->free_result();
            $query->close();
            echo json_encode($user_data);
        }
        elseif (str_starts_with($endpointName, '/persons/search')) {
            // Filter persons by query parameters
            // Get all persons
            $query_str = 'SELECT personID, personGUID, fname, lname, email, username, activated, userlevel, 
       advancelevel, clusterAuthorizations FROM people';
            $query_params = [];
            $query_params_type = '';
            if ( $USER_DATA['userlevel'] < 3 ) {
                $query_str .= " WHERE personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type = 'i';
            }
            if (isset($params['personID']) && is_numeric($params['personID'])) {
                $query .= " AND personID = ?";
                $query_params[] = (int)$params['personID'];
                $query_params_type .= 'i';
            }
            if (isset($params['firstName'])) {
                $query .= " AND fname like ?";
                $query_params[] = '%' . $params['firstName'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['lastName'])) {
                $query .= " AND lname like ?";
                $query_params[] = '%' . $params['lastName'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['username'])) {
                $query .= " AND username like ?";
                $query_params[] = '%' . $params['username'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['email'])) {
                $query .= " AND email like ?";
                $query_params[] = '%' . $params['email'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['personGUID'])) {
                $query .= " AND personGUID = ";
                $query_params[] = $params['personGUID'];
                $query_params_type .= 's';
            }
            $query = $link->prepare($query_str);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'No persons found']);
                exit();
            }
            $persons = [];
            while ($row = mysqli_fetch_array($result)) {
                $persons[] = array(
                    'personID' => $row['personID'],
                    'personGUID' => $row['personGUID'],
                    'firstName' => $row['fname'],
                    'lastName' => $row['lname'],
                    'email' => $row['email'],
                    'username' => $row['username'],
                    'activated' => (bool)$row['activated'],
                    'userLevel' => $row['userlevel'],
                    'advancedLevel' => $row['advancelevel'],
                    'clusterAuthorizations' => $row['clusterAuthorizations']
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($persons);
        }
        // Get all raw data
        elseif ($endpointName === '/rawdata') {
            // Get all raw data this user is associated with, ignoring US_ADMIN or US_SUPER permissions to everything
            $query  = "SELECT distinct 
            r.rawDataID,
            r.label,
            r.experimentID,
            r.filename,
            r.comment,
            r.solutionID,
            TRIM(TRAILING CHAR(0x00) FROM CONVERT (substr(data from 27 for 240) USING utf8)) as description
            from rawData r
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            ";
            $query_params = [];
            $query_params_type = '';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " WHERE ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query." ORDER BY r.filename, r.lastUpdated desc");
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            $rawdata = [];
            while (list($rawDataID, $label, $experimentID, $filename, $comment, $solutionID, $description) = mysqli_fetch_array($result)) {
                $rawdata[] = array(
                    'rawDataID' => $rawDataID,
                    'label' => $label,
                    'experimentID' => $experimentID,
                    'filename' => $filename,
                    'comment' => $comment,
                    'solutionID' => $solutionID,
                    'description' => $description
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($rawdata);
        }
        // Get raw data details
        elseif (preg_match('/^\/rawdata\/(\d+)$/', $endpointName, $matches)) {
            // Get a specific raw data by ID
            $rawDataID = $matches[1];
            $query_params = [$rawDataID];
            $query_params_type = 'i';
            $query  = "SELECT distinct  r.rawDataID, r.label, r.experimentID, r.filename, r.comment, r.solutionID,
                                 r.lastUpdated, TRIM(TRAILING CHAR(0x00) FROM CONVERT (substr(data from 27 for 240) USING utf8)) as description
            from rawData r
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE r.rawDataID = ?";
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));

            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Raw data not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple raw data found with the same ID']);
                exit();
            }
            $rawdata = [];
            while (list($rawID, $label, $experimentID, $filename, $comment, $solutionID, $last_updated, $description) = mysqli_fetch_array($result)) {
                $rawdata = array(
                    'rawDataID' => $rawID,
                    'label' => $label,
                    'experimentID' => $experimentID,
                    'filename' => $filename,
                    'comment' => $comment,
                    'solutionID' => $solutionID,
                    'lastUpdated' => $last_updated,
                    'description' => $description
                );
            }
            $query->free_result();
            $query->close();
            # get edit ids
            $query = $link->prepare("SELECT editedDataID, label, filename FROM editedData WHERE rawDataID = ?");
            $query->bind_param("i", $rawDataID);
            $query->execute();
            $result = $query->get_result();
            $edit_ids = [];
            while (list($edit_id, $label, $filename) = mysqli_fetch_array($result))
            {
                $edit_ids[] = array(
                    'editedDataID' => $edit_id,
                    'label' => $label,
                    'filename' => $filename
                );
            }
            $query->free_result();
            $query->close();
            $rawdata['edits'] = $edit_ids;


            echo json_encode($rawdata);
        }
        // Get raw data data
        elseif (preg_match('/^\/rawdata\/(\d+)\/data$/', $endpointName, $matches)) {
            // Get a specific raw data by ID
            $rawDataID = $matches[1];
            $query_params = [$rawDataID];
            $query_params_type = 'i';
            $query  = "SELECT distinct  r.rawDataID, r.data
            from rawData r
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE r.rawDataID = ?";
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));

            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Raw data not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple raw data found with the same ID']);
                exit();
            }
            $rawdata = [];
            while (list($rawID, $data) = mysqli_fetch_array($result)) {
                $rawdata['dataID'] = $rawID;
                $rawdata['data'] = $data;
                $rawdata['dataType'] = 'RawData';
                $rawdata['dataFormat'] = 'binary';
            }
            $result->free_result();
            $query->free_result();
            $query->close();
            echo json_encode($rawdata);
        }
        // GET HPCAnalysisRequest for raw data
        elseif (preg_match('/^\/rawdata\/(\d+)\/hpcrequests$/', $endpointName, $matches)) {
            // Get a specific experiment by ID
            $experimentID = $matches[1];
            $query_str  = "SELECT distinct e.experimentID,
            e.dateUpdated as udate, projectID, runID, label, instrumentID, operatorID, rotorID, rotorCalibrationID,
             experimentGUID, type, runType, dateBegin, runTemp, comment
             FROM rawData r join experiment e on r.experimentID = e.experimentID join experimentPerson ep on ep.experimentID = e.experimentID  
             WHERE r.rawDataID = ?";
            if ($USER_DATA['userlevel'] < 3) {
                $query_str .= " AND ep.personID = ?";
                $query = $link->prepare($query_str);
                $query->bind_param("ii", $USER_DATA['id'],$experimentID);
            }
            else
            {
                $query = $link->prepare($query_str);
                $query->bind_param("i", $experimentID);
            }
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Raw Data not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple rawdata found with the same ID']);
                exit();
            }
            $query->free_result();
            $query->close();
            // get connected HPCAnalysisRequest
            $query = $link->prepare("SELECT HPCAnalysisRequestID,
       investigatorGUID, submitterGUID FROM HPCAnalysisRequest hpc 
           join HPCDataset hd on hpc.HPCAnalysisRequestID = hd.HPCAnalysisRequestID
                                       JOIN editedData ed on hd.editedDataID = ed.editedDataID
            WHERE ed.rawDataID = ?");
            $query->bind_param("i", $experimentID);
            $query->execute();
            $result = $query->get_result();
            $hpcrequests = [];
            while (list($HPCAnalysisRequestID, $investigatorGUID, $submitterGUID) = mysqli_fetch_array($result)) {
                $hpcrequests[] = array(
                    'HPCAnalysisRequestID' => $HPCAnalysisRequestID,
                    'investigatorGUID' => $investigatorGUID,
                    'submitterGUID' => $submitterGUID
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($hpcrequests);
        }
        // Get rawdata models
        elseif (preg_match('/^\/rawdata\/(\d+)\/models$/', $endpointName, $matches)) {
            $editedDataID = $matches[1];
            // check if user has access to this edit
            $query  = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.rawDataID = ?";
            $query_params = [$editedDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Edit not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple edits found with the same ID']);
                exit();
            }
            $edit = [];
            while (list($editID, $rawDataID, $editGUID, $label, $filename, $comment, $lastUpdated) = mysqli_fetch_array($result)) {
                $edit = array(
                    'editedDataID' => $editID,
                    'rawDataID' => $rawDataID,
                    'editGUID' => $editGUID,
                    'label' => $label,
                    'filename' => $filename,
                    'comment' => $comment,
                    'lastUpdated' => $lastUpdated
                );
            }
            $query->free_result();
            $query->close();

            // get models
            $query  = $link->prepare("SELECT m.modelID, m.description, m.globalType, m.meniscus,
       m.MCIteration, m.variance, m.lastUpdated, hpcar.HPCAnalysisRequestID
            from model m
            left outer join HPCAnalysisResultData hpcard on m.modelID = hpcard.resultID and hpcard.HPCAnalysisResultType = 'model'
            LEFT OUTER JOIN HPCAnalysisResult hpcar on hpcard.HPCAnalysisResultID = hpcar.HPCAnalysisResultID
            WHERE m.editedDataID = ? order by m.lastUpdated desc");
            $query->bind_param("i", $editedDataID);
            $query->execute();
            $result = $query->get_result();
            $models = [];
            while (list($modelID, $description, $globalType, $mensicus, $mc_iter, $var, $lastUpdated, $hpcID) = mysqli_fetch_array($result))
            {
                $model = array(
                    'modelID' => $modelID,
                    'description' => $description,
                    'globalType' => $globalType,
                    'meniscus' => $mensicus,
                    'MCIteration' => $mc_iter,
                    'variance' => $var,
                    'lastUpdated' => $lastUpdated,
                    'HPCAnalysisRequestID' => $hpcID
                );
                // get latest noise ids
                $query_noise  = $link->prepare("SELECT distinct 
    n.noiseID, n.noiseType, n.timeEntered, n.modelID
            from noise n
            WHERE n.modelID = ? ORDER BY n.timeEntered desc");
                $query_noise->bind_param("i", $model['modelID']);
                $query_noise->execute();
                $result_noise = $query_noise->get_result();
                if ($result_noise->num_rows > 0) {
                    while (list($noiseID, $noiseType, $timeEntered, $modelID) = mysqli_fetch_array($result_noise)) {
                        if (isset($noiseType)) {
                            $model[$noiseType] = array(
                                'noiseID' => $noiseID,
                                'noiseType' => $noiseType,
                                'timeEntered' => $timeEntered
                            );
                        }
                    }
                }
                $query_noise->free_result();
                $query_noise->close();
                $models[] = $model;
            }
            $query->free_result();
            $query->close();
            echo json_encode($models);
        }
        // Search raw data
        elseif (str_starts_with($endpointName, '/rawdata/search')) {
            // Filter rawdata by query parameters
            parse_str($parsedURI['query'], $params);
            $query_params = [];
            $query_params_type = '';
            $query  = "SELECT distinct 
            r.rawDataID,
            r.label,
            r.experimentID,
            r.filename,
            r.comment,
            r.solutionID,
            TRIM(TRAILING CHAR(0x00) FROM CONVERT (substr(data from 27 for 240) USING utf8)) as description
            from rawData r
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            JOIN experiment e on r.experimentID = e.experimentID
            JOIN project p on e.projectID = p.projectID
             ";
            if ($USER_DATA['userlevel'] < 3) {
                $query .= "where ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            else {
                $query .= "where 1 = 1";
            }
            if (isset($params['projectID']) && is_numeric($params['projectID'])) {
                $query .= " AND e.projectID = ?";
                $query_params[] = (int)$params['projectID'];
                $query_params_type .= 'i';
            }
            if (isset($params['runID'])) {
                $query .= " AND r.runID like ?";
                $query_params[] = '%' . $params['runID'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['label'])) {
                $query .= " AND r.label like ?";
                $query_params[] = '%' . $params['label'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['comment'])) {
                $query .= " AND r.comment like ?";
                $query_params[] = '%' . $params['comment'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['filename'])) {
                $query .= " AND r.filename like ?";
                $query_params[] = '%' . $params['filename'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['project'])) {
                $query .= " AND p.description like ?";
                $query_params[] = '%' . $params['project'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['solutionID'])) {
                $query .= " AND r.solutionID = ?";
                $query_params[] = (int)$params['solutionID'];
                $query_params_type .= 'i';
            }
            if (isset($params['experimentID'])) {
                $query .= " AND r.experimentID = ?";
                $query_params[] = (int)$params['experimentID'];
                $query_params_type .= 'i';
            }
            if (isset($params['description']))
            {
                $query .= " AND TRIM(TRAILING CHAR(0x00) FROM CONVERT (substr(data from 27 for 240) USING utf8)) like ?";
                $query_params[] = '%' . $params['description'] . '%';
                $query_params_type .= 's';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            $rawdatas = [];
            while (list($rawID, $label, $experimentID, $filename, $comment, $solutionID, $description) = mysqli_fetch_array($result)) {
                $rawdatas[] = array(
                    'rawDataID' => $rawID,
                    'label' => $label,
                    'experimentID' => $experimentID,
                    'filename' => $filename,
                    'comment' => $comment,
                    'solutionID' => $solutionID,
                    'description' => $description
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($rawdatas);
        }
        // Get edit details
        elseif (preg_match('/^\/edits\/(\d+)$/', $endpointName, $matches)) {
            $rawDataID = $matches[1];
            $query  = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$rawDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Raw data not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple raw data found with the same ID']);
                exit();
            }
            $edit = [];
            while (list($editID, $rawDataID, $editGUID, $label, $filename, $comment, $lastUpdated) = mysqli_fetch_array($result)) {
                $edit = array(
                    'editedDataID' => $editID,
                    'rawDataID' => $rawDataID,
                    'editGUID' => $editGUID,
                    'label' => $label,
                    'filename' => $filename,
                    'comment' => $comment,
                    'lastUpdated' => $lastUpdated
                );
            }
            $query->free_result();
            $query->close();
            // get models
            $query  = $link->prepare("SELECT m.modelID, m.description, m.globalType
            from model m
            WHERE m.editedDataID = ? order by m.lastUpdated desc");
            $query->bind_param("i", $edit['editedDataID']);
            $query->execute();
            $result = $query->get_result();
            $models = [];
            while (list($modelID, $description, $globalType) = mysqli_fetch_array($result)) {
                $model = array(
                    'modelID' => $modelID,
                    'description' => $description,
                    'globalType' => $globalType
                );
                $models[] = $model;
            }
            $query->free_result();
            $query->close();
            $edit['models'] = $models;
            // get latest noise ids
            $query  = $link->prepare("SELECT distinct
    n.noiseID, n.noiseType, n.timeEntered, n.modelID
            from noise n
            WHERE n.editedDataID = ? ORDER BY n.noiseType, n.timeEntered desc");
            $query->bind_param("i", $edit['editedDataID']);
            $query->execute();
            $result = $query->get_result();
            while (list($noiseID, $noiseType, $timeEntered, $modelID) = mysqli_fetch_array($result)) {
                if (isset($noiseType)) {
                    $edit[$noiseType] = array(
                        'noiseID' => $noiseID,
                        'noiseType' => $noiseType,
                        'timeEntered' => $timeEntered,
                        'modelID' => $modelID
                    );
                }
            }
            $query->free_result();
            $query->close();
            // get latest hpc request
            $query = $link->prepare("SELECT 
    hpcar.HPCAnalysisRequestID from HPCAnalysisRequest hpcar 
        join HPCDataset hpcd on hpcar.HPCAnalysisRequestID = hpcd.HPCAnalysisRequestID
JOIN HPCAnalysisResult hpcres on hpcar.HPCAnalysisRequestID = hpcres.HPCAnalysisRequestID
where hpcd.editedDataID = ? order by hpcar.submitTime desc limit 1");
            $query->bind_param("i", $edit['editedDataID']);
            $query->execute();
            $result = $query->get_result();
            $hpcanalysisrequest = [];
            if ($result->num_rows === 0) {
                $edit['hpcanalysisrequest'] = [];
            }
            else {
                while (list($HPCAnalysisRequestID) = mysqli_fetch_array($result)) {
                    $hpcanalysisrequest = array(
                        'HPCAnalysisRequestID' => $HPCAnalysisRequestID
                    );
                }
                $edit['hpcanalysisrequest'] = $hpcanalysisrequest;
            }
            $query->free_result();
            $query->close();
            $edit['hpcanalysisrequest'] = $hpcanalysisrequest;
            echo json_encode($edit);
        }
        // Get edit data
        elseif (preg_match('/^\/edits\/(\d+)\/data$/', $endpointName, $matches)) {
            $rawDataID = $matches[1];
            $query  = "SELECT distinct
    e.editedDataID, e.data
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$rawDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Edits data not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple edits data found with the same ID']);
                exit();
            }
            $edit = [];
            while (list($editID, $data) = mysqli_fetch_array($result)) {
                $edit['dataID'] = $editID;
                $edit['data'] = $data;
                $edit['dataType'] = 'Edit';
                $edit['dataFormat'] = 'xml';

            }
            $result->free_result();
            $query->free_result();
            $query->close();
            echo json_encode($edit);
        }
        // GET HPCAnalysisRequest for edit
        elseif (preg_match('/^\/edits\/(\d+)\/hpcrequests$/', $endpointName, $matches)) {
            // Get a specific experiment by ID
            $experimentID = $matches[1];
            $query_str  = "SELECT distinct e.experimentID,
            e.dateUpdated as udate, projectID, runID, e.label, instrumentID, operatorID, rotorID, rotorCalibrationID,
             experimentGUID, type, runType, dateBegin, runTemp, e.comment
             FROM editedData edits join rawData r on r.rawDataID = edits.rawDataID 
                 join experiment e on r.experimentID = e.experimentID 
                 join experimentPerson ep on ep.experimentID = e.experimentID  
             WHERE edits.editedDataID = ?";
            if ($USER_DATA['userlevel'] < 3) {
                $query_str .= " AND ep.personID = ?";
                $query = $link->prepare($query_str);
                $query->bind_param("ii", $USER_DATA['id'],$experimentID);
            }
            else
            {
                $query = $link->prepare($query_str);
                $query->bind_param("i", $experimentID);
            }
            $query->execute();
            $result = $query->get_result()
            or die("Query failed : $query<br />\n" . mysqli_error($link));
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Edit not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple edits found with the same ID']);
                exit();
            }
            $query->free_result();
            $query->close();
            // get connected HPCAnalysisRequest
            $query = $link->prepare("SELECT HPCAnalysisRequestID,
       investigatorGUID, submitterGUID FROM HPCAnalysisRequest hpc 
           join HPCDataset hd on hpc.HPCAnalysisRequestID = hd.HPCAnalysisRequestID
                                       JOIN editedData ed on hd.editedDataID = ed.editedDataID
            WHERE ed.rawDataID = ?");
            $query->bind_param("i", $experimentID);
            $query->execute();
            $result = $query->get_result();
            $hpcrequests = [];
            while (list($HPCAnalysisRequestID, $investigatorGUID, $submitterGUID) = mysqli_fetch_array($result)) {
                $hpcrequests[] = array(
                    'HPCAnalysisRequestID' => $HPCAnalysisRequestID,
                    'investigatorGUID' => $investigatorGUID,
                    'submitterGUID' => $submitterGUID
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($hpcrequests);
        }
        // Get edit models
        elseif (preg_match('/^\/edits\/(\d+)\/models$/', $endpointName, $matches)) {
            $editedDataID = $matches[1];
            // check if user has access to this edit
            $query  = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$editedDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Edit not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple edits found with the same ID']);
                exit();
            }
            $edit = [];
            while (list($editID, $rawDataID, $editGUID, $label, $filename, $comment, $lastUpdated) = mysqli_fetch_array($result)) {
                $edit = array(
                    'editedDataID' => $editID,
                    'rawDataID' => $rawDataID,
                    'editGUID' => $editGUID,
                    'label' => $label,
                    'filename' => $filename,
                    'comment' => $comment,
                    'lastUpdated' => $lastUpdated
                );
            }
            $query->free_result();
            $query->close();

            // get models
            $query  = $link->prepare("SELECT m.modelID, m.description, m.globalType, m.meniscus,
       m.MCIteration, m.variance, m.lastUpdated, hpcar.HPCAnalysisRequestID
            from model m
            left outer join HPCAnalysisResultData hpcard on m.modelID = hpcard.resultID and hpcard.HPCAnalysisResultType = 'model'
            LEFT OUTER JOIN HPCAnalysisResult hpcar on hpcard.HPCAnalysisResultID = hpcar.HPCAnalysisResultID
            WHERE m.editedDataID = ? order by m.lastUpdated desc");
            $query->bind_param("i", $editedDataID);
            $query->execute();
            $result = $query->get_result();
            $models = [];
            while (list($modelID, $description, $globalType, $mensicus, $mc_iter, $var, $lastUpdated, $hpcID) = mysqli_fetch_array($result))
            {
                $model = array(
                    'modelID' => $modelID,
                    'description' => $description,
                    'globalType' => $globalType,
                    'meniscus' => $mensicus,
                    'MCIteration' => $mc_iter,
                    'variance' => $var,
                    'lastUpdated' => $lastUpdated,
                    'HPCAnalysisRequestID' => $hpcID
                );
                // get latest noise ids
                $query_noise  = $link->prepare("SELECT distinct 
    n.noiseID, n.noiseType, n.timeEntered, n.modelID
            from noise n
            WHERE n.modelID = ? ORDER BY n.timeEntered desc");
                $query_noise->bind_param("i", $model['modelID']);
                $query_noise->execute();
                $result_noise = $query_noise->get_result();
                if ($result_noise->num_rows > 0) {
                    while (list($noiseID, $noiseType, $timeEntered, $modelID) = mysqli_fetch_array($result_noise)) {
                        if (isset($noiseType)) {
                            $model[$noiseType] = array(
                                'noiseID' => $noiseID,
                                'noiseType' => $noiseType,
                                'timeEntered' => $timeEntered
                            );
                        }
                    }
                }
                $query_noise->free_result();
                $query_noise->close();
                $models[] = $model;
            }
            $query->free_result();
            $query->close();
            echo json_encode($models);
        }
        // Get model details
        elseif (preg_match('/^\/models\/(\d+)$/', $endpointName, $matches)) {
            $modelID_in = $matches[1];
            $query = "SELECT modelID, editedDataID from model WHERE modelID = ?";
            $query_params = [$modelID_in];
            $query_params_type = 'i';
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params) or die("Query failed : $query<br />\n" . mysqli_error($link));
            $query->execute() or die("Query failed : $query<br />\n" . mysqli_error($link));
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Model not found']);
                exit();
            } elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple models found with the same ID']);
                exit();
            }
            list($modelID, $editedDataID) = mysqli_fetch_array($result);
            $result->free_result();
            $query->free_result();
            $query->close();
            // check if user has access to this edit
            $query  = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$editedDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Model not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple models found with the same ID']);
                exit();
            }
            $edit = [];
            $response = [];
            while (list($editID, $rawDataID, $editGUID, $label, $filename, $comment, $lastUpdated) = mysqli_fetch_array($result)) {
                $edit = array(
                    'editedDataID' => $editID,
                    'rawDataID' => $rawDataID,
                    'editGUID' => $editGUID,
                    'label' => $label,
                    'filename' => $filename,
                    'comment' => $comment,
                    'lastUpdated' => $lastUpdated
                );
            }
            $response['edit'] = $edit;
            $result->free_result();
            $query->free_result();
            $query->close();
            // get model
            $query  = $link->prepare("SELECT m.description, m.globalType, m.meniscus,
       m.MCIteration, m.variance, m.lastUpdated, hpcar.HPCAnalysisRequestID
            from model m
            left outer join HPCAnalysisResultData hpcard on m.modelID = hpcard.resultID and hpcard.HPCAnalysisResultType = 'model'
            LEFT OUTER JOIN HPCAnalysisResult hpcar on hpcard.HPCAnalysisResultID = hpcar.HPCAnalysisResultID
            WHERE m.modelID = ? order by m.lastUpdated desc");
            $query->bind_param("i", $modelID);
            $query->execute();
            $result = $query->get_result();
            $models = [];
            while (list($description, $globalType, $mensicus, $mc_iter, $var, $lastUpdated, $hpcID) = mysqli_fetch_array($result))
            {
                $response['modelID'] = $modelID;
                $response['description'] = $description;
                $response['globalType'] = $globalType;
                $response['meniscus'] = $mensicus;
                $response['MCIteration'] = $mc_iter;
                $response['variance'] = $var;
                $response['lastUpdated'] = $lastUpdated;
                $response['HPCAnalysisRequestID'] = $hpcID;

                // get latest noise ids
                $query_noise  = $link->prepare("SELECT distinct 
    n.noiseID, n.noiseType, n.timeEntered, n.modelID
            from noise n
            WHERE n.modelID = ? ORDER BY n.timeEntered desc");
                $query_noise->bind_param("i", $model['modelID']);
                $query_noise->execute();
                $result_noise = $query_noise->get_result();
                if ($result_noise->num_rows > 0) {
                    while (list($noiseID, $noiseType, $timeEntered, $modelID) = mysqli_fetch_array($result_noise)) {
                        if (isset($noiseType)) {
                            $response[$noiseType] = array(
                                'noiseID' => $noiseID,
                                'noiseType' => $noiseType,
                                'timeEntered' => $timeEntered
                            );
                        }
                    }
                }
                $query_noise->free_result();
                $query_noise->close();
            }
            $query->free_result();
            $query->close();
            echo json_encode($response);
        }
        // Get model data
        elseif (preg_match('/^\/models\/(\d+)\/data$/', $endpointName, $matches)) {
            $modelID_in = $matches[1];
            $query = "SELECT modelID, editedDataID from model WHERE modelID = ?";
            $query_params = [$modelID_in];
            $query_params_type = 'i';
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params) or die("Query failed : $query<br />\n" . mysqli_error($link));
            $query->execute() or die("Query failed : $query<br />\n" . mysqli_error($link));
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Model not found']);
                exit();
            } elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple models found with the same ID']);
                exit();
            }
            list($modelID, $editedDataID) = mysqli_fetch_array($result);
            $result->free_result();
            $query->free_result();
            $query->close();
            // check if user has access to this edit
            $query  = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$editedDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Model not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple models found with the same ID']);
                exit();
            }
            $result->free_result();
            $query->free_result();
            $query->close();
            // get model
            $query  = $link->prepare("SELECT m.xml
            from model m
            WHERE m.modelID = ? order by m.lastUpdated desc");
            $query->bind_param("i", $modelID);
            $query->execute();
            $result = $query->get_result();
            $models = [];
            while (list($xml) = mysqli_fetch_array($result))
            {
                $response['dataID'] = $modelID;
                $response['dataType'] = 'Model';
                $response['dataFormat'] = 'xml';
                $response['data'] = $xml;
            }
            $result->free_result();
            $query->free_result();
            $query->close();
            echo json_encode($response);
        }
        // Get noise details
        elseif (preg_match('/^\/noises\/(\d+)$/', $endpointName, $matches)) {
            $noiseID_in = $matches[1];
            $query = "SELECT noiseID, editedDataID, modelId, noiseType, description, timeEntered from noise WHERE noiseID = ?";
            $query_params = [$noiseID_in];
            $query_params_type = 'i';
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params) or die("Query failed : $query<br />\n" . mysqli_error($link));
            $query->execute() or die("Query failed : $query<br />\n" . mysqli_error($link));
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Noise not found']);
                exit();
            } elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple noises found with the same ID']);
                exit();
            }
            list($noiseID, $editedDataID, $modelID, $noiseType, $desc, $entered) = mysqli_fetch_array($result);
            $response = [
                'noiseID' => $noiseID,
                'editedDataID' => $editedDataID,
                'modelID' => $modelID,
                'noiseType' => $noiseType,
                'description' => $desc,
                'timeEntered' => $entered
            ];
            $result->free_result();
            $query->free_result();
            $query->close();
            // check if user has access to this edit
            $query = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$editedDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Noise not found']);
                exit();
            } elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple noises found with the same ID']);
                exit();
            }
            echo json_encode($response);
        }
        // Get noise data
        elseif (preg_match('/^\/noises\/(\d+)\/data$/', $endpointName, $matches)) {
            $noiseID_in = $matches[1];
            $query = "SELECT noiseID, editedDataID, xml  from noise WHERE noiseID = ?";
            $query_params = [$noiseID_in];
            $query_params_type = 'i';
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params) or die("Query failed : $query<br />\n" . mysqli_error($link));
            $query->execute() or die("Query failed : $query<br />\n" . mysqli_error($link));
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Noise not found']);
                exit();
            } elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple noises found with the same ID']);
                exit();
            }
            list($noiseID, $editedDataID, $xml) = mysqli_fetch_array($result);
            $response = [
                'dataID' => $noiseID,
                'dataType' => 'Noise',
                'dataFormat' => 'xml',
                'data' => $xml
            ];
            $result->free_result();
            $query->free_result();
            $query->close();
            // check if user has access to this edit
            $query = "SELECT distinct
    e.editedDataID, e.rawDataID, e.editGUID, e.label, e.filename, e.comment, e.lastUpdated
            from editedData e 
            join rawData r on e.rawDataID = r.rawDataID
            JOIN experimentPerson ep on r.experimentID = ep.experimentID
            WHERE e.editedDataID = ?";
            $query_params = [$editedDataID];
            $query_params_type = 'i';
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " AND ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Noise not found']);
                exit();
            } elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple noises found with the same ID']);
                exit();
            }
            echo json_encode($response);
        }
        // Filter HPCAnalysisRequest
        elseif (str_starts_with($endpointName, '/hpcrequests/search')) {
            parse_str($parsedURI['query'], $params);
            $query_params = [];
            $query_params_type = '';
            $query  = "SELECT distinct              
            h.HPCAnalysisRequestID, h.experimentID, h.submitTime, h.clusterName, h.method, m.analType
            from HPCAnalysisRequest h
            JOIN HPCDataset d on h.HPCAnalysisRequestID = d.HPCAnalysisRequestID
            JOIN HPCAnalysisResult r on h.HPCAnalysisRequestID = r.HPCAnalysisRequestID
            JOIN experimentPerson ep on d.experimentID = ep.experimentID
            ";
            if ($USER_DATA['userlevel'] < 3) {
                $query .= " WHERE ep.personID = ?";
                $query_params[] = $USER_DATA['id'];
                $query_params_type .= 'i';
            }
            else {
                $query .= " WHERE 1 = 1";
            }
            if (isset($params['experimentID']) && is_numeric($params['experimentID'])) {
                $query .= " AND d.experimentID = ?";
                $query_params[] = (int)$params['experimentID'];
                $query_params_type .= 'i';
            }
            if (isset($params['submitTime'])) {
                $query .= " AND h.submitTime like ?";
                $query_params[] = '%' . $params['submitTime'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['cluster'])) {
                $query .= " AND h.clusterName like ?";
                $query_params[] = '%' . $params['cluster'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['method'])) {
                $query .= " AND m.method like ?";
                $query_params[] = '%' . $params['method'] . '%';
                $query_params_type .= 's';
            }
            if (isset($params['analType'])) {
                $query .= " AND m.analType like ?";
                $query_params[] = $params['analType'];
                $query_params_type .= 's';
            }
            if (isset($params['status'])) {
                $query .= " AND r.queueStatus like ?";
                $query_params[] = $params['status'];
                $query_params_type .= 's';
            }
            if (isset($params['editedDataID']) && is_numeric($params['editedDataID'])) {
                $query .= " AND d.editedDataID = ?";
                $query_params[] = (int)$params['editedDataID'];
                $query_params_type .= 'i';
            }
            if (isset($params['gfacID']) && is_numeric($params['gfacID'])) {
                $query .= " AND r.gfacID = ?";
                $query_params[] = (int)$params['gfacID'];
                $query_params_type .= 'i';
            }
            $query = $link->prepare($query);
            $query->bind_param($query_params_type, ...$query_params);
            $query->execute();
            $result = $query->get_result();
            $hpcrequests = [];
            while (list($HPCAnalysisRequestID, $experimentID, $submitTime, $clusterName, $method, $analType) = mysqli_fetch_array($result)) {
                $hpcrequests[] = array(
                    'HPCAnalysisRequestID' => $HPCAnalysisRequestID,
                    'experimentID' => $experimentID,
                    'submitTime' => $submitTime,
                    'cluster' => $clusterName,
                    'method' => $method,
                    'analType' => $analType
                );
            }
            $query->free_result();
            $query->close();
            echo json_encode($hpcrequests);
        }
        // Get HPCAnalysisRequest details
        elseif (preg_match('/^\/hpcrequests\/(\d+)$/', $endpointName, $matches)) {
            $HPCAnalysisRequestID = $matches[1];
            $query  = $link->prepare("SELECT distinct              
            h.HPCAnalysisRequestID, h.experimentID, h.submitTime, h.clusterName, h.method, h.analType, 
            r.queueStatus, r.lastMessage, r.updateTime, r.startTime, r.endTime, r.gfacID
            from HPCAnalysisRequest h
            LEFT OUTER JOIN HPCDataset d on h.HPCAnalysisRequestID = d.HPCAnalysisRequestID
            LEFT OUTER JOIN HPCAnalysisResult r on h.HPCAnalysisRequestID = r.HPCAnalysisRequestID
            JOIN experimentPerson ep on h.experimentID = ep.experimentID
            WHERE (ep.personID = ? or ? > 2) AND h.HPCAnalysisRequestID = ?");
            $query->bind_param("iii", $USER_DATA['id'],$USER_DATA['userlevel'], $HPCAnalysisRequestID);
            $query->execute();
            $result = $query->get_result();
            if ($result->num_rows === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'HPCAnalysisRequest not found']);
                exit();
            }
            elseif ($result->num_rows != 1) {
                http_response_code(500);
                echo json_encode(['error' => 'Multiple HPCAnalysisRequest found with the same ID']);
                exit();
            }
            $hpcrequest = [];
            while (list($id, $experimentID, $submitTime, $cluster, $method, $analType, $queueStatus, $lastMessage, $updateTime, $startTime, $endTime, $gfacID) = mysqli_fetch_array($result)) {
                $hpcrequest = array(
                    'HPCAnalysisRequestID' => $id,
                    'experimentID' => $experimentID,
                    'submitTime' => $submitTime,
                    'cluster' => $cluster,
                    'method' => $method,
                    'analType' => $analType,
                    'queueStatus' => $queueStatus,
                    'lastMessage' => $lastMessage,
                    'updateTime' => $updateTime,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    'gfacID' => $gfacID
                );
            }
            $query->free_result();
            $query->close();
            // get datasets
            $query  = $link->prepare("SELECT d.HPCDatasetID, d.editedDataID, d.HPCAnalysisRequestID, e.label, e.filename, e.rawDataID,
d.simpoints, d.band_volume, d.radial_grid, d.time_grid
            from HPCDataset d
            join editedData e on d.editedDataID = e.editedDataID
            WHERE d.HPCAnalysisRequestID = ?");
            $query->bind_param("i", $hpcrequest['HPCAnalysisRequestID']);
            $query->execute();
            $result = $query->get_result();
            $datasets = [];
            while (list($HPCDatasetID, $editedDataID, $id, $label, $filename, $rawDataID, $simpoints, $b_vol, $rad, $tim) = mysqli_fetch_array($result)) {
                $dataset = array(
                    'HPCDatasetID' => $HPCDatasetID,
                    'editedDataID' => $editedDataID,
                    'rawDataID' => $rawDataID,
                    'label' => $label,
                    'filename' => $filename,
                    'simpoints' => $simpoints,
                    'band_volume' => $b_vol,
                    'radial_grid' => parseRadialGrid($rad),
                    'time_grid' => parseTimeGrid($tim)
                );
                // get noise
                $query_noise  = $link->prepare("SELECT n.noiseID, n.noiseType, n.modelID, n.description, n.editedDataID, timeEntered from HPCRequestData d join noise n on n.noiseID = d.noiseID where HPCDatasetID = ?");
                $query_noise->bind_param("i", $dataset['HPCDatasetID']);
                $query_noise->execute();
                $result_noise = $query_noise->get_result();
                $noises = [];
                while (list($n_id, $n_t, $n_m, $n_d, $n_eid, $entered) = mysqli_fetch_array($result_noise)) {
                    $noises[] = array(
                        'noiseID' => $n_id,
                        'noiseType' => $n_t,
                        'modelID' => $n_m,
                        'description' => $n_d,
                        'editedDataID' => $n_eid,
                        'timeEntered' => $entered
                    );
                }
                $dataset['noises'] = $noises;
                $query_noise->free_result();
                $query_noise->close();
                $datasets[] = $dataset;
            }
            $query->free_result();
            $query->close();
            $hpcrequest['datasets'] = $datasets;
            // get results
            $query  = $link->prepare("SELECT * from HPCAnalysisResult r 
    join HPCAnalysisResultData d on d.HPCAnalysisResultID = r.HPCAnalysisResultID
            where r.HPCAnalysisRequestID = ?");
            $query->bind_param("i", $hpcrequest['HPCAnalysisRequestID']);
            $query->execute();
            $result = $query->get_result();
            $results = [];
            while ($row = mysqli_fetch_array($result)) {
                $results[] = array(
                    'result_type'=> $row['HPCAnalysisResultType'],
                    'result_id' => $row['resultID'],
                );
            }
            $query->free_result();
            $query->close();
            $hpcrequest['results'] = $results;
            echo json_encode($hpcrequest);
        }
        //
        else {
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found ' . $endpointName]);
        }
        break;
    case 'POST':
        // Create a new HPCAnalysisRequest
        if ($endpointName === '/hpcrequests') {
            // Get Body of the post request
            $rawData = file_get_contents('php://input');
            $data = json_decode($rawData, true);
            $requestdata = array();
            // Check if the required fields are present

            // check for separate_datasets

            // check for edit_select_type

            // check for advanced_review

            // check for submitter_email
            $submitter_email = $USER_DATA['email'];
            if ( isset($data['submitter_email'])) {
                $submitter_email = $data['submitter_email'];
            }
            else if ( isset($_POST['submitter_email'])) {
                $submitter_email = $_POST['submitter_email'];
            }
            elseif ( isset($data['new_submitter'])) {
                $submitter_email = $data['new_submitter'];
            }
            elseif ( isset($_POST['new_submitter'])) {
                $submitter_email = $_POST['new_submitter'];
            }
            $data['submitter_email'] = $submitter_email;
            $data['add_owner'] = ( isset($data['add_owner']) && $data['add_owner'] ) ? 1 : 0;
            // Get basic job parameter
            $dataset_count = count($data['datasets']);
            $separate_datasets = ( isset($data['separate_datasets']) && $data['separate_datasets'] ) ? 1 : 0;
            $USER_DATA['dataset_count'] = $dataset_count;
            $USER_DATA['datasetCount'] = $dataset_count;
            $USER_DATA['separate_datasets'] = $separate_datasets;
            $submitter = new Submitter_2DSA($USER_DATA, $data);
            $submitter->submit();
            $response = array();
            $response['success'] = 'HPCAnalysisRequest created';
            $response['HPCAnalysisRequestIDs'] = $submitter->submitted_requests;
            $response['results'] = $submitter->result;
            echo json_encode($response);
            exit();







        }
        echo json_encode(['error' => 'Endpoint not found']);
        break;

}



