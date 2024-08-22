<?php
/*
 * A class to encapsulate the submitting of data
 *
 */

include_once 'checkinstance.php';
elogrs( __FILE__ );
include_once 'config.php';
global $full_path;
include_once $full_path . 'db.php';
include_once $full_path . 'lib/utility.php';
global $class_dir;
include_once $class_dir . 'submit_local.php';
include_once $class_dir . 'submit_gfac.php';
include_once $class_dir . 'submit_airavata.php';
include_once $class_dir . 'priority.php';

abstract class Submitter
{
    // input
    /**
     * $USER_DATA
     *
     * Represents the user data received from the api request headers
     */
    var $USER_DATA = array();
    /**
     * $input
     *
     * Represents the data submitted with the api request
     */
    var $input = array();

    // organized data for submission
    /**
     * Stores the cluster information.
     *
     * @var array
     */
    var $cluster = array();
    /**
     * Stores the analysis parameters for the submission.
     *
     * @var array
     */
    var $parameters = array();

    /**
     * $datasets
     *
     * Represents an array of datasets.
     */
    var $datasets = [];

    // computed variables to replicate normal workflow
    var $session = array();
    var $post = array();

    // used for submission
    var $HPC;
    var $file;
    var $payload;
    var $filenames;
    var $HPCAnalysisRequestID = 0;

    // output
    var $submitted_requests = [];
    var $result = [];

    public function __construct( $USER_DATA, $input )
    {
        $this->USER_DATA = $USER_DATA;
        $this->input = $input;
        $this->construct_basic_session();
        $this->init_variables();
    }

    abstract function init_variables();
    abstract function construct_payload();
    abstract function construct_post();
    abstract function construct_params();
    abstract function prepare_submit();
    abstract function submit();
    abstract function test();
    abstract function create_HPC_job_params($HPCAnalysisRequestID, $params);


    /**
     * Construct the cluster based on input parameters and stores it in $this->cluster.
     * Relies on $this->input['clusternode'] and $this->USER_DATA['gwhostid']
     */
    public function construct_cluster(): void
    {
        $cluster = array();
        if ( isset($this->input['clusternode']) )
        {
            $gwhostid   = 'uslims3';
            if ( isset( $this->USER_DATA['gwhostid'] ) )
                $gwhostid   = $this->USER_DATA[ 'gwhostid' ];
            list( $cluster_name, $cluster_shortname, $queue ) = explode(":", $this->input['clusternode'] );
            if ( preg_match( "/alamo/", $gwhostid )  &&  $cluster_shortname == 'alamo' )
            {  // alamo-to-alamo uses alamo-local as cluster
                $cluster_shortname = 'alamo-local';
            }
            $cluster['name']      = $cluster_name;
            $cluster['shortname'] = $cluster_shortname;
            if ( $cluster_shortname == 'jacinto' )
            {
                if ( isset($this->input['separate_datasets']) )
                {
                    if ( $this->input['separate_datasets'] == 0 )
                    {
                        $queue = 'ngenseq';
                    }
                }


            }
            $cluster['queue']     = $queue;
        }

        $this->cluster = $cluster;
    }

    /**
     * Check if an instance is set.
     *
     * @return bool Returns true if the instance is set, otherwise returns false.
     */
    public function check_instance(): bool
    {
        if ( !isset( $this->USER_DATA['instance'] ) )
        {
            return false;
        }
        include_once 'lib/motd.php';
        if ( motd_isblocked() && ($this->USER_DATA['userlevel'] < 4) )
        {
            $errstr =  "ERROR: " . __FILE__ . " Job submission is blocked";
            $cli_errors[] = $errstr;
            $this->result['error'] = $errstr;
            return false;
        }
        return true;
    }

    /**
     * Check if the user level is sufficient.
     *
     * @return bool Returns true if the user level is sufficient, otherwise returns false.
     */
    public function check_user(): bool
    {
        if ( ($this->USER_DATA['userlevel'] != 2) &&
             ($this->USER_DATA['userlevel'] != 3) &&
             ($this->USER_DATA['userlevel'] != 4) &&
             ($this->USER_DATA['userlevel'] != 5) )    // only data analyst and up
        {
            $errstr = "ERROR: " . __FILE__ . " user level is insufficient";
            $cli_errors[] = $errstr;
            $this->result['error'] = $errstr;
            return false;
        }
        return true;
    }

    /**
     * Retrieves the noise ID for a given edit.
     *
     * @param int $edit_id The ID of the edit.
     * @param string $noise_type The type of noise ('ti_noise' or 'ri_noise').
     * @param int $noise_id The ID of the noise (-3 by default).
     * @return int The noise ID, or one of the following error codes:
     *               0 if the noise ID is 0.
     *              -1 if the noise type is invalid.
     *              -2 if no noise is found for the given edit_id.
     *              -3 if no noise is found for the given edit_id and noise_id.
     */
    public function get_noise_for_edit($edit_id, $noise_type, int $noise_id = -3): int
    {
        global $link;
        if ( $noise_type != 'ti_noise' && $noise_type != 'ri_noise' )
        {
            return -1;
        }
        if ( $noise_id == 0 )
        {
            return 0;
        }

        $queryparams = [$edit_id, $noise_type];
        $querytypes = 'is';
        if ( $noise_id == -1 )
        {
            // get latest noise date for this edit
            $query = $link->prepare("SELECT unix_timestamp(max(timeEntered)) as timeEntered from noise n where editedDataID = ?");
            $query->bind_param('i', $edit_id);
            $query->execute();
            $result = $query->get_result();
            if ( $result->num_rows == 0 )
            {
                return -2;
            }
            $row = $result->fetch_array();
            $timeEntered = (int)$row['timeEntered'];
            $querystr = "SELECT noiseID FROM noise n WHERE editedDataID = ? and noiseType = ? and timeEntered = FROM_UNIXTIME(?) 
                            ORDER BY timeEntered DESC LIMIT 1";
            $queryparams[] = $timeEntered;
            $querytypes .= 'i';
        }
        else
        {
            $queryparams[] = $noise_id;
            $querytypes .= 'i';
            $querystr = "SELECT noiseID FROM noise n WHERE editedDataID = ? and noiseType = ? and noiseID = ? ORDER BY timeEntered DESC LIMIT 1";
        }

        $query = $link->prepare($querystr);
        $query->bind_param($querytypes, ...$queryparams);
        $query->execute();
        $result = $query->get_result();
        if ( $result->num_rows == 0 )
        {
            return -3;
        }
        $row = $result->fetch_array();
        $n_id = $row['noiseID'];
        $query->free_result();
        $query->close();
        return $n_id;


    }

    /**
     * Constructs the basic session variable using USER_DATA.
     *
     * @return void
     */
    public function construct_basic_session(): void
    {
        // construct the session variable from USER_DATA
        $session = array();
        $session['submitter_email'] = $this->USER_DATA['submitter_email'];
        $session['cluster'] =
        $session['gwhostid'] = $this->USER_DATA['gwhostid'];
        $session['email'] = $this->USER_DATA['email'];
        $session['user_id'] = $this->USER_DATA['user_id'];
        $session['separate_datasets'] = $this->parse_separate_datasets($this->input['separate_datasets']??1);
        $session['id'] = $this->USER_DATA['id'];
        $session['loginID'] = $this->USER_DATA['loginID'];
        $session['advancelevel'] = $this->USER_DATA['advancelevel']??0;
        $session['userlevel'] = $this->USER_DATA['userlevel']??1;
        $session['instance'] = $this->USER_DATA['instance'];
        $session['lastname'] = $this->USER_DATA['last_name'];
        $session['firstname'] = $this->USER_DATA['first_name'];
        $session['clusterAuth'] = $this->USER_DATA['clusterAuth'];
        $session['advanced_review'] = 1;
        if ( !isset($this->input['investigator_id']) )
        {
            $session['investigator_id'] = $this->USER_DATA['loginID'];
            $this->input['investigator_id'] = $this->USER_DATA['loginID'];
            $this->USER_DATA['investigator_id'] = $this->USER_DATA['loginID'];
        }
        if ( !isset($this->input['submitter_id']) )
        {
            $session['submitter_id'] = $this->USER_DATA['loginID'];
            $this->input['submitter_id'] = $this->USER_DATA['loginID'];
            $this->USER_DATA['submitter_id'] = $this->USER_DATA['loginID'];
        }

        $this->session = $session;
    }

    /**
     * Constructs a triple from the given edit ID, ti noise ID, and ri noise ID.
     *
     * @param int $edit_id The edit ID.
     * @param int $ti_noise_id The ti noise ID.
     * @param int $ri_noise_id The ri noise ID.
     *
     * @return array The constructed triple as an array with the following keys:
     *               - 'editedDataID': The edit ID.
     *               - 'error': The error message if any.
     *               - 'rawDataID': The raw data ID.
     *               - 'experimentID': The experiment ID.
     *               - 'filename': The filename.
     *               - 'editFilename': The edit filename.
     *               - 'path': The path.
     *               - 'editMeniscus': The edit meniscus.
     *               - 'dataLeft': The data range left.
     *               - 'dataRight': The data range right.
     *               - 'noiseIDs': An array of noise IDs.
     *
     * @throws Exception When there is an error preparing the query or getting the result.
     */
    public function construct_triple_from_edit($edit_id, $ti_noise_id, $ri_noise_id): array
    {
        $triple = array();
        $triple['editedDataID'] = $edit_id;
        $triple['error'] = '';
        global $link;
        $querystr = '';
        $queryparams = [];
        $querytypes = '';
        if ( $this->USER_DATA['userlevel'] > 2 )
        {
            $querystr = "SELECT r.filename as r_filename, r.rawDataID as rawDataID, r.experimentID as experimentID,
       e.filename as e_filename, e.data as e_data
       FROM editedData e join rawData r on r.rawDataID = e.rawDataID 
         WHERE e.editedDataID = ?";
            $queryparams[] = $edit_id;
            $querytypes .= 'i';
        }
        else
        {
            $querystr = "SELECT r.filename as r_filename, r.rawDataID as rawDataID, r.experimentID as experimentID,
       e.filename as e_filename, e.data as e_data FROM editedData e join rawData r on r.rawDataID = e.rawDataID 
         join experimentPerson ep on ep.experimentID = r.experimentID 
         WHERE ep.personID = ? AND e.editedDataID = ?";
            $queryparams[] = $this->input['investigator_id'];
            $queryparams[] = $edit_id;
            $querytypes .= 'ii';
        }
        $query = $link->prepare($querystr);
        $query->bind_param($querytypes, ...$queryparams);
        $query->execute();
        $result = $query->get_result();
        if ( $result->num_rows == 0 )
        {
            $triple['error'] .= "No data found for edit ID $edit_id";
            return $triple;
        }
        $row = $result->fetch_array();
        $triple['rawDataID'] = $row['rawDataID'];
        $triple['experimentID'] = $row['experimentID'];
        $triple['filename'] = $row['r_filename'];
        $triple['auc'] = $triple['filename'];
        $triple['editFilename'] = $row['e_filename'];
        $triple['edit'] = $row['e_filename'];
        $triple['path'] = '.';
        $parser = new XMLReader();
        $parser->xml($row['e_data']);
        while( $parser->read() )
        {
            $type = $parser->nodeType;

            if ( $type == XMLReader::ELEMENT )
            {
                $name = $parser->name;

                if ( $name == "meniscus" )
                {
                    $parser->moveToAttribute( 'radius' );
                    $triple['editMeniscus'] = $parser->value;
                }

                else if ( $name == 'data_range' )
                {
                    $parser->moveToAttribute( 'left' );
                    $triple['dataLeft'] = $parser->value;

                    $parser->moveToAttribute( 'right' );
                    $triple['dataRight'] = $parser->value;
                }
            }
        }

        $parser->close();
        $query->free_result();
        $query->close();
        $triple['noiseIDs'] = array();
        if ( $ti_noise_id != 0 )
        {
            $checked_ti_noise = $this->get_noise_for_edit($edit_id, 'ti_noise', $ti_noise_id);
            if ( $checked_ti_noise > 0 )
            {
                $triple['noiseIDs'][] = $checked_ti_noise;
            }
            elseif ( $checked_ti_noise == -3 )
            {
                $triple['error'] .= "No t noise found for edit ID $edit_id and t noise ID $ti_noise_id";
            }
            elseif ( $checked_ti_noise == -2 )
            {
                $triple['error'] .= "No t noise found for edit ID $edit_id , no matter the $ti_noise_id";
            }
        }
        if ( $ri_noise_id != 0 )
        {
            $checked_ri_noise = $this->get_noise_for_edit($edit_id, 'ri_noise', $ri_noise_id);
            if ( $checked_ri_noise > 0 )
            {
                $triple['noiseIDs'][] = $checked_ri_noise;
            }
            elseif ( $checked_ri_noise == -3 )
            {
                $triple['error'] .= "No r noise found for edit ID $edit_id and r noise ID $ri_noise_id";
            }
            elseif ( $checked_ri_noise == -2 )
            {
                $triple['error'] .= "No r noise found for edit ID $edit_id , no matter the $ri_noise_id";
            }
        }
        return $triple;
    }

    /**
     * Constructs a triple from the given rawdata ID, ti_noise ID, and ri_noise ID.
     *
     * @param int $rawdata_id The rawdata ID.
     * @param int $ti_noise_id The ti_noise ID.
     * @param int $ri_noise_id The ri_noise ID.
     *
     * @return array The constructed triple as an associative array with the following keys:
     *               - 'error': Contains any error message encountered during the construction.
     *               - 'rawDataID': The rawData ID.
     *               - 'experimentID': The experiment ID.
     *               - 'filename': The filename of the rawData.
     *               - 'editFilename': The filename of the editedData.
     *               - 'editedDataID': The editedData ID.
     *               - 'path': The path of the triple.
     *               - 'editMeniscus': The radius of the editMeniscus.
     *               - 'dataLeft': The left value of the data_range.
     *               - 'dataRight': The right value of the data_range.
     *               - 'noiseIDs': An array containing the noiseIDs.
     *                 - If ti_noise_id != 0, it will contain the checked ti_noise value.
     *                 - If ri_noise_id != 0, it will contain the checked ri_noise value.
     *               - If an error occurred during construction, 'error' will be populated with an error message.
     *                 Otherwise, 'error' will be empty.
     */
    public function construct_triple_from_ra($rawdata_id, $ti_noise_id, $ri_noise_id): array
    {
        $triple = array();

        $triple['error'] = '';
        global $link;
        $querystr = '';
        $queryparams = [];
        $querytypes = '';
        if ( $this->USER_DATA['userlevel'] > 2 )
        {
            $querystr = "SELECT r.filename as r_filename, r.rawDataID as rawDataID, r.experimentID as experimentID,
       e.filename as e_filename, e.data as e_data
       FROM editedData e join rawData r on r.rawDataID = e.rawDataID 
         WHERE e.rawDataID = ?";
            $queryparams[] = $rawdata_id;
            $querytypes .= 'i';
        }
        else
        {
            $querystr = "SELECT r.filename as r_filename, r.rawDataID as rawDataID, r.experimentID as experimentID,
       e.filename as e_filename, e.data as e_data FROM editedData e join rawData r on r.rawDataID = e.rawDataID 
         join experimentPerson ep on ep.experimentID = r.experimentID 
         WHERE ep.personID = ? AND e.rawDataID = ?";
            $queryparams[] = $this->input['investigator_id'];
            $queryparams[] = $rawdata_id;
            $querytypes .= 'ii';
        }
        $query = $link->prepare($querystr);
        $query->bind_param($querytypes, ...$queryparams);
        $query->execute();
        $result = $query->get_result();
        if ( $result->num_rows == 0 )
        {
            $triple['error'] .= "No data found for raw ID $rawdata_id";
            return $triple;
        }
        $row = $result->fetch_array();
        $triple['rawDataID'] = $row['rawDataID'];
        $triple['experimentID'] = $row['experimentID'];
        $triple['filename'] = $row['r_filename'];
        $triple['auc'] = $row['r_filename'];
        $triple['editFilename'] = $row['e_filename'];
        $triple['edit'] = $row['e_filename'];
        $triple['editedDataID'] = $row['editedDataID'];
        $edit_id = $row['editedDataID'];
        $triple['path'] = '.';
        $parser = new XMLReader();
        $parser->xml($row['e_data']);
        while( $parser->read() )
        {
            $type = $parser->nodeType;

            if ( $type == XMLReader::ELEMENT )
            {
                $name = $parser->name;

                if ( $name == "meniscus" )
                {
                    $parser->moveToAttribute( 'radius' );
                    $triple['editMeniscus'] = $parser->value;
                }

                else if ( $name == 'data_range' )
                {
                    $parser->moveToAttribute( 'left' );
                    $triple['dataLeft'] = $parser->value;

                    $parser->moveToAttribute( 'right' );
                    $triple['dataRight'] = $parser->value;
                }
            }
        }

        $parser->close();
        $query->free_result();
        $query->close();
        $triple['noiseIDs'] = array();
        if ( $ti_noise_id != 0 )
        {
            $checked_ti_noise = $this->get_noise_for_edit($edit_id, 'ti_noise', $ti_noise_id);
            if ( $checked_ti_noise > 0 )
            {
                $triple['noiseIDs'][] = $checked_ti_noise;
            }
            elseif ( $checked_ti_noise == -3 )
            {
                $triple['error'] .= "No t noise found for edit ID $edit_id and t noise ID $ti_noise_id";
            }
            elseif ( $checked_ti_noise == -2 )
            {
                $triple['error'] .= "No t noise found for edit ID $edit_id , no matter the $ti_noise_id";
            }
        }
        if ( $ri_noise_id != 0 )
        {
            $checked_ri_noise = $this->get_noise_for_edit($edit_id, 'ri_noise', $ri_noise_id);
            if ( $checked_ri_noise > 0 )
            {
                $triple['noiseIDs'][] = $checked_ri_noise;
            }
            elseif ( $checked_ri_noise == -3 )
            {
                $triple['error'] .= "No r noise found for edit ID $edit_id and r noise ID $ri_noise_id";
            }
            elseif ( $checked_ri_noise == -2 )
            {
                $triple['error'] .= "No r noise found for edit ID $edit_id , no matter the $ri_noise_id";
            }
        }
        return $triple;
    }

    /**
     * Creates a High Performance Computing (HPC) analysis request.
     *
     * This method is responsible for creating an analysis request in the HPC system using the USER_DATA provided by the client.
     * It assigns the generated HPCAnalysisRequestID to the instance variable HPCAnalysisRequestID.
     *
     * @return void
     */
    public function create_HPC_analysis_request( $data ): int
    {

        global $link;
        // Get any remaining information we need
        // investigatorGUID
        $query  = "SELECT personGUID FROM people " .
            "WHERE personID = {$this->input['investigator_id']} ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        list( $investigatorGUID ) = mysqli_fetch_array( $result );

        // submitterGUID
        $query  = "SELECT personGUID FROM people " .
            "WHERE personID = {$this->USER_DATA['loginID']} ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        list( $submitterGUID ) = mysqli_fetch_array( $result );

        $guid = uuid();
        $analType = $this->post['analType'];
        if ( isset( $data['analType'] ) )
            $analType = $data['analType'];
        // What about $job['cluster']['shortname'] and $job['cluster']['queue']?
        $query  = "INSERT INTO HPCAnalysisRequest SET " .
            "HPCAnalysisRequestGUID = '$guid', " .
            "investigatorGUID = '$investigatorGUID', " .
            "submitterGUID = '$submitterGUID', " .
            "email = '{$this->USER_DATA['submitter_email']}', " .
            "experimentID = '{$data['datasets'][0]['experimentID']}', " .
            "submitTime =  now(), " .
            "clusterName = '{$this->cluster['name']}', " .
            "analType = '$analType', " .
            "method = '{$this->post['method']}' " ;
        mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));

        // Return the generated ID
        return ( mysqli_insert_id( $link ) );
    }

    /**
     * Prepares the datasets based on the input values.
     *
     * This method constructs and stores an array of datasets based on the input values.
     * If the 'separate_datasets' value is greater than 0, it iterates over the 'datasets' input
     * and constructs a triple from the edit values, including the 'sim_points' and 'band_volume'
     * values from either the corresponding dataset or the job parameters. If there is an error in
     * the edit data, it logs the error and skips to the next dataset. The constructed dataset is
     * then added to the datasets array.
     *
     * If the 'separate_datasets' value is 0, it constructs a triple from the 'rawdata_id', 'ti_noise',
     * and 'ri_noise' input values and adds it as the first (and only) dataset in the datasets array.
     *
     * @return void
     */
    public function prepare_datasets(): void
    {
        $datasets = array();
        $dataset_count = count( $this->input['datasets'] );
        for ( $i = 0; $i < $dataset_count; $i++ )
        {
            $input_data = $this->input['datasets'][$i];
            if ( isset( $input_data['edit_id']) && $input_data['edit_id'] > 0 )
            {
                $editdata = $this->construct_triple_from_edit( $input_data['edit_id'],
                    $input_data['ti_noise'],
                    $input_data['ri_noise'] );
            }
            else
            {
                $editdata = $this->construct_triple_from_ra( $input_data['rawdata_id'],
                    $input_data['ti_noise'],
                    $input_data['ri_noise'] );
            }
            if ( $editdata['error'] != '' )
            {
                elogrs($editdata['error']);
                continue;
            }
            $editdata['simpoints'] = $input_data['sim_points']??$this->input['job_parameters']['sim_points'];
            $editdata['band_volume'] = $input_data['band_volume']??$this->input['job_parameters']['band_volume'];
            $editdata['radial_grid'] = $this->parse_radial_grid( $input_data['radial_grid']??$this->input['job_parameters']['radial_grid'] );
            $editdata['time_grid'] = $this->parse_radial_grid( $input_data['time_grid']??$this->input['job_parameters']['time_grid'] );
            $editdata['files'] = array();
            $editdata['parameters'] = array();
            // get model concentration
            $editdata['model_concentration'] = $this->get_model_concentration( $editdata['editedDataID'] );
            $this->get_database_parameters( $editdata['rawDataID'], $editdata );
            $datasets[] = $editdata;
        }
        $this->datasets = $datasets;

    }

    public function get_model_concentration( $editedDataID ): float
    {
        global $link;
        $tot_conc = 0.0;
        $modelXML = "";
        $query    = "SELECT xml FROM model " .
            "WHERE editedDataID = $editedDataID " .
            "AND description LIKE '%2DSA%IT%' " .
            "AND description NOT LIKE '%-GL-%' " .
            "ORDER BY modelID DESC";
        $result   = mysqli_query( $link, $query )
        or die( "Query failed : $query<br/>\n" . mysqli_error($link) );

        if ( mysqli_num_rows( $result ) > 0 )
        {
            list( $modelXML ) = mysqli_fetch_array( $result );

            if ( $modelXML != "" )
            {
                $tot_conc = $this->total_concentration( $modelXML );
            }
        }
        else
        {
            $tot_conc = -1;   // Mark no 2DSA-IT found
        }
        return $tot_conc;
    }

    public function get_database_parameters( $rawDataID, &$params )
    {
        global $link;
        $timelast  = 0;

        // we need the stretch function from the rotor table
        $rotor_stretch = "0 0";
        $cellname      = "1";
        $channel       = "A";
        $chanindex     = 0;
        $query  = "SELECT coeff1, coeff2, filename, rawData.experimentID " .
            "FROM rawData, experiment, rotorCalibration " .
            "WHERE rawData.rawDataID = $rawDataID " .
            "AND rawData.experimentID = experiment.experimentID " .
            "AND experiment.rotorCalibrationID = rotorCalibration.rotorCalibrationID ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        if ( mysqli_num_rows( $result ) > 0 )
        {
            list( $coeff1, $coeff2, $filename, $experID ) = mysqli_fetch_array( $result );   // should be 1
            $rotor_stretch = "$coeff1 $coeff2";
            list( $run, $dtype, $cellname, $channel, $waveln, $ftype ) = explode( ".", $filename );
            $chanindex = strpos( "ABCDEFGH", $channel ) / 2;
        }

        // We may need speedsteps information
        $speedsteps = array();
        $query  = "SELECT speedstepID, speedstep.experimentID, scans, durationhrs, durationmins, " .
            "delayhrs, delaymins, rotorspeed, acceleration, accelerflag, " .
            " w2tfirst, w2tlast, timefirst, timelast " .
            "FROM rawData, speedstep " .
            "WHERE rawData.rawDataID = $rawDataID " .
            "AND rawData.experimentID = speedstep.experimentID ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        while ( list( $stepID, $expID, $scans, $durhrs, $durmins, $dlyhrs, $dlymins,
            $speed, $accel, $accflag, $w2tf, $w2tl, $timef, $timel ) = mysqli_fetch_array( $result ) )
        {
            $speedstep['stepID']  = $stepID;
            $speedstep['expID']   = $expID;
            $speedstep['scans']   = $scans;
            $speedstep['durhrs']  = $durhrs;
            $speedstep['durmins'] = $durmins;
            $speedstep['dlyhrs']  = $dlyhrs;
            $speedstep['dlymins'] = $dlymins;
            $speedstep['speed']   = $speed;
            $speedstep['accel']   = $accel;
            $speedstep['accflag'] = $accflag;
            $speedstep['w2tf']    = $w2tf;
            $speedstep['w2tl']    = $w2tl;
            $speedstep['timef']   = $timef;
            $speedstep['timel']   = $timel;

            $speedsteps[] = $speedstep;

            if ( $timel > $timelast )
                $timelast     = $timel;
        }

        // We need the centerpiece bottom
        $centerpiece_bottom      = 7.3;
        $centerpiece_shape       = 'standard';
        $centerpiece_angle       = 2.5;
        $centerpiece_pathlength  = 1.2;
        $centerpiece_width       = 0.0;

        $query  = "SELECT abstractChannel.shape, abstractChannel.bottom, abstractChannel.angle, ".
            "abstractChannel.pathLength, abstractChannel.width " .
            "FROM rawData, cell, abstractCenterpiece, abstractChannel " .
            "WHERE rawData.rawDataID = $rawDataID " .
            "AND rawData.experimentID = cell.experimentID " .
            "AND cell.name = $cellname " .
            "AND cell.abstractCenterpieceID = abstractCenterpiece.abstractCenterpieceID " .
            "AND abstractCenterpiece.abstractCenterpieceID = abstractChannel.abstractCenterpieceID ".
            "AND abstractChannel.name = '$channel' ";
        $result = mysqli_query( $link, $query );
        if (mysqli_num_rows( $result ) < 1 ){
            $query  = "SELECT shape, bottom, angle, pathLength, width " .
                "FROM rawData, cell, abstractCenterpiece " .
                "WHERE rawData.rawDataID = $rawDataID " .
                "AND rawData.experimentID = cell.experimentID " .
                "AND cell.name = $cellname " .
                "AND cell.abstractCenterpieceID = abstractCenterpiece.abstractCenterpieceID";
            $result = mysqli_query( $link, $query )
            or die( "Query failed : $query<br />" . mysqli_error($link));
        }
        if ( mysqli_num_rows ( $result ) > 0 )
            list( $centerpiece_shape, $centerpiece_bottom, $centerpiece_angle, $centerpiece_pathlength, $centerpiece_width )
                = mysqli_fetch_array( $result );      // should be 1
        if ( strpos( $centerpiece_bottom, ":" ) !== false )
        { // Parse multiple bottoms and get the one for the channel set
            $bottoms            = explode( ":", $centerpiece_bottom );
            $centerpiece_bottom = $bottoms[ $chanindex ];
        }

        // We also need some information about the analytes in this cell
        $analytes = array();
        $query  = "SELECT type, vbar, molecularWeight, amount " .
            "FROM rawData, solutionAnalyte, analyte " .
            "WHERE rawData.rawDataID = $rawDataID " .
            "AND rawData.solutionID = solutionAnalyte.solutionID " .
            "AND solutionAnalyte.analyteID = analyte.analyteID ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        while ( list( $type, $vbar, $mw, $amount ) = mysqli_fetch_array( $result ) )
        {
            $analyte['type']   = $type;
            $analyte['vbar']   = $vbar;
            $analyte['mw']     = $mw;
            $analyte['amount'] = $amount;

            $analytes[] = $analyte;
        }

        // Finally, some buffer information
        $density     = 0.0;
        $viscosity   = 0.0;
        $compress    = 0.0;
        $manual      = 0;
        $smanual     = 0;
        $description = '';
        $bufferID = '';
        $query  = "SELECT buffer.bufferID, viscosity, density, description, compressibility, manual " .
            "FROM rawData, solutionBuffer, buffer " .
            "WHERE rawData.rawDataID = $rawDataID " .
            "AND rawData.solutionID = solutionBuffer.solutionID " .
            "AND solutionBuffer.bufferID = buffer.bufferID ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        if ( mysqli_num_rows ( $result ) > 0 )
            list( $bufferID, $viscosity, $density, $description, $compress, $manual ) = mysqli_fetch_array( $result ); // should be 1

        // Turn on 'manual' flag where '  [M]' is present in buffer description
        str_replace( '  [M]', '', $description, $smanual );
        $manual      = ( $smanual != 0 ) ? $smanual : $manual;
        // fetch for cosed components
        $cosedcomponents = array();
        $query = "SELECT cosedComponentID, name, concentration, s_value, d_value, density, viscosity, overlaying, vbar " .
            "FROM buffercosedLink WHERE bufferID = $bufferID";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        while ( list( $id, $name, $conc, $s, $D, $dens, $visc, $overlay, $vbar ) = mysqli_fetch_array( $result ) )
        {
            $cosed['name']      = $name;
            $cosed['id']        = $id;
            $cosed['conc']      = $conc;
            $cosed['s']         = $s;
            $cosed['vbar']      = $vbar;
            $cosed['D']         = $D;
            $cosed['dens']      = $dens;
            $cosed['visc']      = $visc;
            $cosed['overlay']   = $overlay;
            $cosedcomponents[]  = $cosed;
        }
        // Save the simulation parameters looked up in the db
        $params['rotor_stretch']           = $rotor_stretch;
        $params['centerpiece_bottom']      = $centerpiece_bottom;
        $params['centerpiece_shape']       = $centerpiece_shape;
        $params['centerpiece_angle']       = $centerpiece_angle;
        $params['centerpiece_pathlength']  = $centerpiece_pathlength;
        $params['centerpiece_width']       = $centerpiece_width;
        $params['density']                 = $density;
        $params['viscosity']               = $viscosity;
        $params['compress']                = $compress;
        $params['manual' ]                 = $manual;
        $params['analytes']                = $analytes;
        $params['cosedcomponents']         = $cosedcomponents;
        $params['speedsteps']              = $speedsteps;
        $params['rawDataID']               = $rawDataID;
        $params['experimentID']            = $experID;
        $params['timelast']                = $timelast;
    }

    // Function to parse a model XML in order to return total concentration
    public function total_concentration( $xml )
    {
        $tot_conc = 0.0;
        $name     = "";

        $parser   = new XMLReader();
        $parser->xml( $xml );

        while( $parser->read() )
        {
            $type       = $parser->nodeType;

            if ( $type == XMLReader::ELEMENT )
            {
                $name       = $parser->name;

                if ( $name == "analyte" )
                {
                    $parser->moveToAttribute( 'signal' );
                    $concen     = (float)$parser->value;
                    $tot_conc  += $concen;
                }
            }
        }
        $parser->close();

        return $tot_conc;
    }

    /**
     * Parses the given radial grid value and returns an integer code.
     *
     * @param mixed $rad_grid The radial grid value to be parsed.
     *
     * @return int The integer code representing the parsed radial grid value:
     *             - 0 if the value is not set or is equal to 'ASTFEM'
     *             - 1 if the value is equal to 'Claverie'
     *             - 2 if the value is equal to 'Moving Hat'
     *             - 3 if the value is equal to 'ASTFVM'
     *             - -1 if none of the above conditions are met
     */
    public function parse_radial_grid($rad_grid): int
    {
        if ( !isset( $rad_grid) || $rad_grid == 'ASTFEM' )
        {
            return 0;
        }
        elseif ( $rad_grid == 'Claverie' )
        {
            return 1;
        }
        elseif ( $rad_grid == 'Moving Hat' )
        {
            return 2;
        }
        elseif ( $rad_grid == 'ASTFVM' )
        {
            return 3;
        }
        return -1;
    }

    /**
     * Parses the given time grid value and returns an integer code.
     *
     * @param mixed $time_grid The time grid value to be parsed.
     *
     * @return int The integer code representing the parsed time grid value:
     *             - 0 if the value is not set or is equal to 'AST'
     *             - 1 if the value is equal to 'Constant'
     *             - -1 if none of the above conditions are met
     */
    public function parse_time_grid($time_grid): int
    {
        if ( !isset( $time_grid ) || $time_grid == 'AST' )
        {
            return 0;
        }
        elseif ( $time_grid == 'Constant' )
        {
            return 1;
        }
        return -1;
    }

    /**
     * Parses the value of $separate_datasets and returns an integer based on its value.
     *
     * This method is responsible for parsing the value of $separate_datasets and determining the appropriate integer value to return.
     * If $separate_datasets is not set or has a value of 1 or "separate", 1 will be returned.
     * If $separate_datasets has a value of 0 or "global", 0 will be returned.
     * If $separate_datasets has a value of 2 or "composite", 2 will be returned.
     * If none of the above conditions are met, -1 will be returned.
     *
     * @param mixed $separate_datasets The value to be parsed and converted to an integer.
     * @return int The parsed integer value based on the value of $separate_datasets.
     */
    public function parse_separate_datasets($separate_datasets): int
    {
        if ( !isset($separate_datasets) || $separate_datasets == 1 || $separate_datasets == "separate" )
        {
            return 1;
        }
        elseif ( $separate_datasets == 0 || $separate_datasets == "global" )
        {
            return 0;
        }
        else if ( $separate_datasets == 2 || $separate_datasets == "composite" )
        {
            return 2;
        }
        return -1;
    }

    /**
     * Computes the number of repetitions for a uniform grid.
     *
     * This method calculates the number of repetitions needed for a uniform grid based on the number
     * of source grid points ($gpoints_s), the number of target grid points ($gpoints_k),
     * and updates the $grid array with the computed repetitions.
     *
     * @param int $gpoints_s The number of source grid points.
     * @param int $gpoints_k The number of target grid points.
     * @param array $grid A reference to an array representing the grid.
     *
     * @return void
     */
    public function compute_uniform_grid_repetitions($gpoints_s, $gpoints_k, &$grid = 0): array
    {
        if ( $gpoints_s < 10 )
            $gpoints_s = 10;
        if ( $gpoints_s > 2100 )
            $gpoints_s = 2100;
        if ( $gpoints_k < 10 )
            $gpoints_k = 10;
        if ( $gpoints_k > 2100 )
            $gpoints_k = 2100;
        // Accumulate a list of grid repetition evenly dividing into S points
        $greps_s   = array();
        $count_grs = 0;
        for ( $jreps = 2; $jreps < 41; $jreps++ )
        {
            $testp     = (int)( $gpoints_s / $jreps ) * $jreps;
            if ( $testp == $gpoints_s )
            {  // Save a repetition that divides evenly into S grid points
                $greps_s[] = $jreps;
                $count_grs++;
            }
        }
        // Find the repetitions and K grid points that work best
        $kdiff     = 99999;
        $kreps     = $greps_s[ 0 ];
        $kgridp_k  = $gpoints_k;
        for ( $jrx = 0; $jrx < $count_grs; $jrx++ )
        {  // Examine each grid repetition from the S list
            $jreps     = $greps_s[ $jrx ];
            $subpts_s  = (int)( $gpoints_s / $jreps );
            $subpts_k  = (int)( $gpoints_k / $jreps );
            $jgridp_k  = $subpts_k * $jreps;
            $nsubgs    = $jreps * $jreps;
            $subgsz    = $subpts_s * $subpts_k;
            $jdiff     = $nsubgs - $subgsz;
            if ( $jdiff < 0 )
                $jdiff     = 0 - $jdiff;
            if ( $jdiff < $kdiff )
            {  // Count and size of subgrid are closely matched
                $kdiff     = $jdiff;
                $kgridp_k  = $jgridp_k;
                $kreps     = $jreps;
            }
        }

        $gridreps  = $kreps;
        $gpoints_k = $kgridp_k;
        $subpts_s  = (int)( $gpoints_s / $gridreps );
        $subpts_k  = (int)( $gpoints_k / $gridreps );
        $gpoints_s = $subpts_s * $gridreps;
        $gpoints_k = $subpts_k * $gridreps;
        $subg_size = $subpts_s * $subpts_k;
        while( $subg_size > 200  ||  $gridreps < 2 )
        {
            $gridreps++;
            $subpts_s  = (int)( $gpoints_s / $gridreps );
            $subpts_k  = (int)( $gpoints_k / $gridreps );
            $subg_size = $subpts_s * $subpts_k;
        }
        while( $subg_size < 40  ||  $gridreps > 160 )
        {
            $gridreps--;
            $subpts_s  = (int)( $gpoints_s / $gridreps );
            $subpts_k  = (int)( $gpoints_k / $gridreps );
            $subg_size = $subpts_s * $subpts_k;
        }
        $gpoints_s = $subpts_s * $gridreps;
        $gpoints_k = $subpts_k * $gridreps;
        if ( !is_numeric($grid)){
            $grid['gridreps'] = $gridreps;
            $grid['gpoints_s'] = $gpoints_s;
            $grid['gpoints_k'] = $gpoints_k;
        }
        return array(
            'grid_repetitions' => $gridreps,
            'grid_points_s' => $gpoints_s,
            'grid_points_k' => $gpoints_k
        );
    }

    public function write_HPC_to_DB( $data, $params ): int
    {
        $HPCAnalysisRequestID = $this->create_HPC_analysis_request( $data );
        $this->create_HPC_job_params($HPCAnalysisRequestID, $params);
        $this->create_HPC_dataset($HPCAnalysisRequestID, $data, $params);
        return $HPCAnalysisRequestID;
    }

    public function create_HPC_dataset($HPCAnalysisRequestID, $job, $params)
    {
        global $link;

        foreach ($job['datasets'] as $dataset) {
            $data = $dataset;
            $query = "INSERT INTO HPCDataset SET " .
                "HPCAnalysisRequestID = $HPCAnalysisRequestID,      " .
                "editedDataID         = {$dataset['editedDataID']}, " .
                "simpoints            = {$params['sim_points']},    " .
                "band_volume          = {$params['band_volume']},  " .
                "radial_grid          = {$params['radial_grid']},  " .
                "time_grid            = {$params['time_grid']},    " .
                "rotor_stretch        = '{$data['rotor_stretch']}' ";
            mysqli_query($link, $query)
            or die("Query failed : $query<br />" . mysqli_error($link));

            $HPCDatasetID = mysqli_insert_id($link);

            // Now for the HPCRequestData table
            if (isset($dataset['noiseIDs'][0]) && $dataset['noiseIDs'][0] > 0) {
                foreach ($dataset['noiseIDs'] as $noiseID) {
                    $query = "INSERT INTO HPCRequestData SET      " .
                        "HPCDatasetID       = $HPCDatasetID, " .
                        "noiseID             = $noiseID       ";
                    mysqli_query($link, $query)
                    or die("Query failed : $query<br />" . mysqli_error($link));
                }
            }
        }
    }
    // Function to write job parameters for a particular analysis type
    abstract protected function writeJobParameters( $xml, $jobParameters );

    // Takes a structured array of data created by the payload manager
    function write( $job, $post, $HPCAnalysisRequestID )
    {
        global $link;
        // Get the rest of the information we need
        $query  = "SELECT HPCAnalysisRequestGUID FROM HPCAnalysisRequest " .
            "WHERE HPCAnalysisRequestID = $HPCAnalysisRequestID ";
        $result = mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
        list( $HPCAnalysisRequestGUID ) = mysqli_fetch_array( $result );

        // First create a directory with a unique name
        if ( ! ( $current_dir = $this->create_dir( $HPCAnalysisRequestGUID ) ) )
            return false;
//return "CANNOT CREATE DIR $HPCAnalysisRequestGUID";

        // Write the auc, edit profile, model and noise files
        // Returns all the filenames used
        if ( ! ( $filenames = $this->write_support_files( $job, $current_dir ) ) )
            return false;
//return "CANNOT WRITE SUPPORT FILES";

        // Determine if this is a global fit
        $global_fit = 0;
        if ( count($job['datasets']) > 1 )
        {
            if ( $this->session['separate_datasets'] )
            {
                $global_fit = 0;
            }
            else
            {
                $global_fit = 1;
                // See if we have all total_concentrations, as needed for global-fit
                $min_totc   = 99999.9;
                $max_steps  = 0;
                foreach ( $job['datasets'] as $dataset_id => $dataset )
                { // Find the minimum total_concentration for all datasets
                    $totc     = $dataset['model_concentration'];
                    if ( $totc < $min_totc )
                        $min_totc  = $totc;
                    $scount   = count( $dataset['speedsteps'] );
                    if ( $scount > $max_steps )
                        $max_steps = $scount;
                }
                $min_totc   = ( $max_steps < 2 ) ? $min_totc : 1;

                if ( $min_totc <= 0.0 )
                { // Return a special flag indicating not all 2DSA-IT present
                    return "2DSA-IT-MISSING";
                }
            }
        }
        global $dbname, $dbhost;
        global $udpport, $ipaddr, $ipa_ext;
        global $ipad_a, $ipae_a;
        // Now write xml file
        $xml_filename = sprintf( "hpcrequest-%s-%s-%05d.xml",
            $dbhost,
            $dbname,
            $HPCAnalysisRequestID );

        $snamclus = $this->cluster['shortname'];

        $server                  = array();
        $server['udpport']       = $udpport;
        $clusname             = $this->cluster['shortname'];
        $gwhostid             = $this->USER_DATA['gwhostid'];
        if ( preg_match( '/alamo/', $clusname )  &&
            preg_match( '/alamo/', $gwhostid ) )
        {  // Use alternate IP addresses for UDP if host,cluster both 'alamo'
            $ipaddr   = $ipad_a;
            $ipa_ext  = $ipae_a;
        }
        $server['ip']            = $ipaddr;
        $server['ip_ext']        = $ipa_ext;
        $ipadserv = $server['ip'];
        if ( preg_match( "/-local/", $snamclus )  &&
            isset( $server['ip_ext'] ) )
            $ipadserv = $server['ip_ext'];
        if ( preg_match( "/GA/", $post['method'] ) )
        { // For "GA" have to do additional check
            if ( preg_match( "/alamo/", $snamclus )  &&
                preg_match( "/alamo/", $this->USER_DATA['gwhostid'] ) )
            { // Use special external alamo-to-alamo UDP server IP
                $ipadserv = $server['ip_ext_aa'];
            }
        }

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent( true );
        $xml->startDocument( '1.0', 'UTF-8', 'yes' );
        $xml->startDTD( 'US_JobSubmit' );
        $xml->endDTD();
        $xml->startElement( 'US_JobSubmit' );
        $xml->writeAttribute( 'method', $post['method'] );
        $xml->writeAttribute( 'version', '1.0' );

        $xml->startElement( 'job' );
        $xml->startElement( 'gateway' );
        $xml->writeAttribute( 'id', $this->USER_DATA['gwhostid'] );
        $xml->endElement(); // gateway
        $xml->startElement( 'cluster' );
        $xml->writeAttribute( 'name', $this->cluster['name'] );
        $xml->writeAttribute( 'shortname', $snamclus );
        $xml->writeAttribute( 'queue', $this->cluster['queue'] );
        $xml->endElement(); // cluster
        $xml->startElement( 'udp' );
        $xml->writeAttribute( 'port', $server['udpport'] );
        $xml->writeAttribute( 'server', $ipadserv );
        $xml->endElement(); // udp
        $xml->startElement( 'directory' );
        $xml->writeAttribute( 'name', $current_dir );
        $xml->endElement(); // directory
        $xml->startElement( 'datasetCount' );
        $xml->writeAttribute( 'value', count( $job['datasets'] ) );
        $xml->endElement(); // datasetCount
        $xml->startElement( 'global_fit' );
        $xml->writeAttribute( 'value', $global_fit );
        $xml->endElement(); // global_fit
        $xml->startElement( 'request' );
        $xml->writeAttribute( 'id', $HPCAnalysisRequestID );
        $xml->writeAttribute( 'guid', $HPCAnalysisRequestGUID );
        $xml->endElement(); // request
        $xml->startElement( 'database' );
        $xml->startElement( 'name' );
        $xml->writeAttribute( 'value', $dbname );
        $xml->endElement(); // name
        $xml->startElement( 'host' );
        $xml->writeAttribute( 'value', $dbhost );
        $xml->endElement(); // host
        $xml->startElement( 'user' );
        $xml->writeAttribute( 'email', $this->USER_DATA['submitter_email'] );
        $xml->writeAttribute( 'user_id', $this->USER_DATA['user_id'] );
        $xml->endElement(); // user
        $xml->startElement( 'submitter' );
        $xml->writeAttribute( 'email', $this->USER_DATA['submitter_email'] );
        $xml->endElement(); // submitter
        $xml->endElement(); // database

        // Now we break out and write the job parameters specific to this method
        $this->writeJobParameters( $xml, $post );
        $xml->startElement( 'analysis_type' );
        $xml->writeAttribute( 'value', $post['analType'] );
        $xml->endElement(); // analysis_type

        $xml->endElement(); // job

        $xml->writeComment( 'the dataset section is repeated for each dataset' );

        foreach ( $job['datasets'] as $dataset_id => $dataset )
        {
            $xml->startElement( 'dataset' );
            $xml->startElement( 'files' );
            $xml->startElement( 'auc' );
            $xml->writeAttribute( 'filename', $filenames[$dataset_id]['auc'] );
            $xml->endElement(); // auc
            $xml->startElement( 'edit' );
            $xml->writeAttribute( 'filename', $filenames[$dataset_id]['edit'] );
            $xml->endElement(); // edit
            /*
                        $xml->startElement( 'model' );
                          $xml->writeAttribute( 'filename', $filenames[$dataset_id]['model'] );
                        $xml->endElement(); // model
            */
            if ( isset( $filenames[$dataset_id]['noise'] ) )
            {
                foreach ( $filenames[$dataset_id]['noise'] as $noiseFile )
                {
                    $xml->startElement( 'noise' );
                    $xml->writeAttribute( 'filename', $noiseFile );
                    $xml->endElement(); // noise
                }
            }
            if ( isset( $filenames[$dataset_id]['tmst_fn'] ) )
            {
                $xml->startElement( 'timestate' );
                $xml->writeAttribute( 'filename', $filenames[$dataset_id]['tmst_fn'] );
                $xml->endElement(); // timestate
            }
            $xml->endElement(); // files
            $xml->startElement( 'parameters' );
            $xml->startElement( 'simpoints' );
            $xml->writeAttribute( 'value', $dataset['simpoints'] );
            $xml->endElement(); // simpoints
            $xml->startElement( 'band_volume' );
            $xml->writeAttribute( 'value', $dataset['band_volume'] );
            $xml->endElement(); // band_volume
            $xml->startElement( 'radial_grid' );
            $xml->writeAttribute( 'value', $dataset['radial_grid'] );
            $xml->endElement(); // radial_grid
            $xml->startElement( 'time_grid' );
            $xml->writeAttribute( 'value', $dataset['time_grid'] );
            $xml->endElement(); // time_grid
            $xml->startElement( 'rotor_stretch' );
            $xml->writeAttribute( 'value', $dataset['rotor_stretch'] );
            $xml->endElement(); // rotor_stretch
            $xml->startElement( 'centerpiece_shape' );
            $xml->writeAttribute( 'value', $dataset['centerpiece_shape'] );
            $xml->endElement(); // centerpiece_shape
            $xml->startElement( 'centerpiece_bottom' );
            $xml->writeAttribute( 'value', $dataset['centerpiece_bottom'] );
            if (! isset($dataset['centerpiece_angle']) )
                $dataset['centerpiece_angle'] = 0.0;
            if (! isset($dataset['centerpiece_width']) )
                $dataset['centerpiece_width'] = 0.0;
            $xml->endElement(); // centerpiece_bottom
            $xml->startElement( 'centerpiece_angle' );
            $xml->writeAttribute( 'value', $dataset['centerpiece_angle'] );
            $xml->endElement(); // centerpiece_angle
            $xml->startElement( 'centerpiece_pathlength' );
            $xml->writeAttribute( 'value', $dataset['centerpiece_pathlength'] );
            $xml->endElement(); // centerpiece_pathlength
            $xml->startElement( 'centerpiece_width' );
            $xml->writeAttribute( 'value', $dataset['centerpiece_width'] );
            $xml->endElement(); // centerpiece_width
            $xml->startElement( 'total_concentration' );
            $xml->writeAttribute( 'value', $dataset['total_concentration']??($dataset['model_concentration']??1) );
            $xml->endElement(); // dataset total concentration

            foreach( $dataset['speedsteps'] as $speedstep )
            {
                $xml->startElement( 'speedstep' );
                $xml->writeAttribute( 'stepID',        $speedstep['stepID']  );
                $xml->writeAttribute( 'rotorspeed',    $speedstep['speed']   );
                $xml->writeAttribute( 'scans',         $speedstep['scans']   );
                $xml->writeAttribute( 'timefirst',     $speedstep['timef']   );
                $xml->writeAttribute( 'timelast',      $speedstep['timel']   );
                $xml->writeAttribute( 'w2tfirst',      $speedstep['w2tf']    );
                $xml->writeAttribute( 'w2tlast',       $speedstep['w2tl']    );
                $xml->writeAttribute( 'duration_hrs',  $speedstep['durhrs']  );
                $xml->writeAttribute( 'duration_mins', $speedstep['durmins'] );
                $xml->writeAttribute( 'delay_hrs',     $speedstep['dlyhrs']  );
                $xml->writeAttribute( 'delay_mins',    $speedstep['dlymins'] );
                $xml->writeAttribute( 'acceleration',  $speedstep['accel']   );
                $xml->writeAttribute( 'accelerflag',   $speedstep['accflag'] );
                $xml->writeAttribute( 'expID',         $speedstep['expID']   );
                $xml->endElement(); // speedstep
            }

            $xml->startElement( 'solution' );
            $xml->startElement( 'buffer' );
            $xml->writeAttribute( 'density', $dataset['density'] );
            $xml->writeAttribute( 'viscosity', $dataset['viscosity'] );
            $xml->writeAttribute( 'manual', $dataset['manual'] );
            foreach( $dataset['cosedcomponents'] as $cosed)
            {
                $xml->startElement('cosedcomponent');
                $xml->writeAttribute('id', $cosed['id']);
                $xml->writeAttribute('name', $cosed['name']);
                $xml->writeAttribute('conc', $cosed['conc']);
                $xml->writeAttribute('s', $cosed['s']);
                $xml->writeAttribute('D', $cosed['D']);
                $xml->writeAttribute('dens', $cosed['dens']);
                $xml->writeAttribute('visc', $cosed['visc']);
                $xml->writeAttribute('vbar', $cosed['vbar']);
                $xml->writeAttribute('overlay', $cosed['overlay']);
                $xml->endElement(); // cosedcomponent
            }
            $xml->endElement(); // buffer
            foreach( $dataset['analytes'] as $analyte )
            {
                $xml->startElement( 'analyte' );
                $xml->writeAttribute( 'vbar20', $analyte['vbar']   );
                $xml->writeAttribute( 'amount', $analyte['amount'] );
                $xml->writeAttribute( 'mw',     $analyte['mw']     );
                $xml->writeAttribute( 'type',   $analyte['type']   );
                $xml->endElement(); // analyte
            }

            $xml->endElement(); //solution
            $xml->endElement(); // parameters
            $xml->endElement(); // dataset
        }

        $xml->endElement(); // US_JobSubmit
        $xml->endDocument();

        $fp = fopen( $current_dir . $xml_filename, 'w');
        fwrite( $fp, $xml->outputMemory() );
        fclose( $fp );

        // Update database with xml file content
        $xml_data = file_get_contents( $current_dir . $xml_filename );

        // Create tar file including all files
        $files = array();
        $files[] = $xml_filename;
        foreach ( $filenames as $filename )
        {
            $files[] = $filename['auc'];
            $files[] = $filename['edit'];
            // $files[] = $filename['model'];

            if ( isset( $filename['noise'] ) )
            {
                foreach ( $filename['noise'] as $noiseFile )
                    $files[] = $noiseFile;
            }

            if ( isset( $filename['CG_model'] ) )
                $files[] = $filename['CG_model'];

            if ( isset( $filename['DC_model'] ) )
                $files[] = $filename['DC_model'];

            if ( isset( $filename['tmst_fn'] ) )
                $files[] = $filename['tmst_fn'];

            if ( isset( $filename['tdef_fn'] ) )
                $files[] = $filename['tdef_fn'];
        }

        $save_cwd = getcwd();         // So we can come back to the current
        // working directory later

        chdir( $current_dir );

        $fileList = implode( " ", $files );
        $tarFilename = sprintf( "hpcinput-%s-%s-%05d.tar",
            $dbhost,
            $dbname,
            $HPCAnalysisRequestID );
        shell_exec( "/bin/tar -cf $tarFilename " . $fileList );

        chdir( $save_cwd );

        return( $current_dir . $xml_filename );
    }

    // Function to write the edit profile, model and noise files
    // If successful, returns a data structure with all the filenames in it
    function write_support_files( $job, $dir )
    {
        global $link;
        $experID   = 0;
        $filenames = array();
        $expIDs    = array();
        foreach ( $job['datasets'] as $dataset_id => $dataset )
        {
            // auc files
            $query  = "SELECT data, experimentID FROM rawData " .
                "WHERE rawDataID = {$dataset['rawDataID']} ";
            $result = mysqli_query( $link, $query )
            or die( "Query failed : $query<br />" . mysqli_error($link));
            list( $aucdata, $expID ) = mysqli_fetch_array( $result );
            if ( $expID != $experID )
            {
                $expIDs[$dataset_id] = $expID;
                $experID             = $expID;
            }
            if ( ! $this->create_file( $dataset['auc'], $dir, $aucdata ) )
                return false;
            $filenames[$dataset_id]['auc'] = $dataset['auc'];

            // edit profile
            $query  = "SELECT data FROM editedData " .
                "WHERE editedDataID = {$dataset['editedDataID']} ";
            $result = mysqli_query( $link, $query )
            or die( "Query failed : $query<br />" . mysqli_error($link));
            list( $edit_profile ) = mysqli_fetch_array( $result );
            if ( ! $this->create_file( $dataset['edit'], $dir, $edit_profile ) )
                return false;
            $filenames[$dataset_id]['edit'] = $dataset['edit'];

            /*
                  // model
                  $query  = "SELECT xml FROM model " .
                            "WHERE modelID = {$dataset['modelID']} ";
                  $result = mysqli_query( $link, $query )
                            or die( "Query failed : $query<br />" . mysqli_error($link));
                  list( $model_contents ) = mysqli_fetch_array( $result );
                  if ( ! ( $model_file = $this->my_tmpname( '.model', '', $dir ) ) )
                    return false;
                  $model_file = basename( $model_file );
                  if ( ! $this->create_file( $model_file, $dir, $model_contents ) )
                    return false;
                  $filenames[$dataset_id]['model'] = $model_file;
            */

            // noise
            foreach ( $dataset['noiseIDs'] as $ndx => $noiseID )
            {
                $query  = "SELECT noiseType, xml FROM noise " .
                    "WHERE noiseID = $noiseID ";
                $result = mysqli_query( $link, $query )
                or die( "Query failed : $query<br />" . mysqli_error($link));
                list( $type, $vector ) = mysqli_fetch_array( $result );
                if ( ! ($noise_file = $this->my_tmpname( ".$type", '', $dir ) ) )
                    return false;
                $noise_file = basename( $noise_file );
                if ( ! $this->create_file( $noise_file, $dir, $vector ) )
                    return false;
                $filenames[$dataset_id]['noise'][$ndx] = $noise_file;
            }

            // TimeState
            if ( isset( $expIDs[$dataset_id] ) )
            { // We have an experiment ID for this dataset
                $expID   = $expIDs[$dataset_id];
                $query   = "SELECT filename, definitions, data, length(data) " .
                    "FROM timestate " .
                    "WHERE experimentID = $expID ";
                $result  = mysqli_query( $link, $query )
                or die( "Query failed : $query<br />" . mysqli_error($link));
                if ( mysqli_num_rows( $result ) > 0 )
                { // TimeState DB record exists:  write the tmst,def files
                    list( $tmst_fn, $def, $data ) = mysqli_fetch_array( $result );

                    if ( $this->create_file( $tmst_fn, $dir, $data ) )
                    { // TMST file successfully created:  create def (xml) file
                        $filenames[$dataset_id]['tmst_fn'] = $tmst_fn;
                        $tdef_fn = $tmst_fn;
                        $tdef_fn = preg_replace( "/\.tmst$/", ".xml", $tdef_fn );
                        if ( $this->create_file( $tdef_fn, $dir, $def ) )
                            $filenames[$dataset_id]['tdef_fn'] = $tdef_fn;
                    } // END: .tmst file write succeeded
                } // END: TimeState record for experiment exists
            } // END: expID for dataset is set
        } // END:  datasets loop

        // In the case of 2DSA_CG files, the CG_model
        if ( isset( $this->input['CG_modelID'] ) )
        {
            $CG_modelID = $this->input['CG_modelID'];
            $query  = "SELECT description, xml " .
                "FROM model " .
                "WHERE modelID = $CG_modelID ";
            $result = mysqli_query( $link, $query )
            or die( "Query failed : $query<br />" . mysqli_error($link));
            list( $fn, $contents ) = mysqli_fetch_array( $result );
            if ( ! $this->create_file( $fn, $dir, $contents ) )
                return false;
            $filenames[0]['CG_model'] = $fn;  // put it in with other dataset[0] files so
            //  as not to confuse the tar file creation

        }

        // In the case of DMGA_Constr files, the DC_model
        if ( isset( $this->input['DC_modelID'] ) )
        {
            $DC_modelID = $this->input['DC_modelID'];
            $query  = "SELECT description, xml " .
                "FROM model " .
                "WHERE modelID = $DC_modelID ";
            $result = mysqli_query( $link, $query )
            or die( "Query failed : $query<br />" . mysqli_error($link));
            list( $fn, $contents ) = mysqli_fetch_array( $result );
            if ( ! $this->create_file( $fn, $dir, $contents ) )
                return false;
            $filenames[0]['DC_model'] = $fn;  // put it in with other dataset[0] files so
            //  as not to confuse the tar file creation

        }

        return( $filenames );
    }

    // Function to create the data subdirectory to write files into
    function create_dir( $dir_name )
    {
        global $submit_dir;

        $dirPath = $submit_dir . $dir_name;
        if ( ! mkdir( $dirPath, 0770 ) )
        {
            echo "\nmkdir failed:  $dirPath\n";
            return false;
        }

        // Ensure that group write permissions are set for us3 user in listen
        // mkdir is influenced by umask, which is system wide and should
        // not be reset for one process
        chmod( $dirPath, 0770 );
        return( $dirPath . "/" );
    }

    // Function to create and open a file, and write data to it if possible
    function create_file( $filename, $dir, $data )
    {
        //echo "\nIn create_file: filename =  $filename\n";
        $dataFile = $dir . $filename;

        if ( ! $fp = fopen( $dataFile, "w" ) )
        {
            echo "fopen failed\n";
            return false;
        }

        if ( ! is_writable( $dataFile ) )
        {
            echo "is_writable failed\n";
            return false;
        }

        if ( fwrite( $fp, $data ) === false )
        {
            echo "fwrite failed\n";
            fclose( $fp );
            return false;
        }

        fclose( $fp );
        return( $dataFile );
    }

    // Function to create a unique filename with given extension
    function my_tmpname( $postfix = '.tmp', $prefix = '', $dir = null )
    {
        // validate arguments
        if ( ! (isset($postfix) && is_string($postfix) ) )
            return false;

        if (! (isset($prefix) && is_string($prefix) ) )
            return false;

        if (! isset($dir) )
            $dir = getcwd();

        // find a temporary name
        $tries = 1;
        while ( $tries <= 5 )
        {
            // get a known, unique temporary file name
            $sysFileName = tempnam($dir, $prefix);
            if ( $sysFileName === false )
                return false;

            // tack on the extension
            $newFileName = $sysFileName . $postfix;
            if ($sysFileName == $newFileName)
                return $sysFileName;

            // move or point the created temporary file to the new filename
            // NOTE: this fails if the new file name exists
            if ( rename( $sysFileName, $newFileName ) )
                return $newFileName;

            $tries++;
        }

        // failed 5 times.
        return false;
    }

    // Some debug functions
    function debug_out( $job )
    {
        echo "<pre>\n";
        echo "Array data\n";
        print_r( $job );
        echo "Session variables: \n";
        print_r( $_SESSION );
        echo "</pre>\n";
    }

    // This function provides email logging without interrupting
    //  the jobs themselves
    function email_log( $job )
    {
        $to = "dzollars@gmail.com";
        $subject = "Logging from {$job['database']['name']} ";
        $message = "job files---\n";
        $message .= $this->__multiarray( $job );
        $message .= "\nSESSION variables---\n";
        $message .= $this->__multiarray( $_SESSION );
        mail($to, $subject, $message);
    }

    // This function parses values in an array, calling itself recursively
    //  as needed for multilevel arrays
    function __multiarray( $job )
    {
        $msg = "";
        static $level = 0;       // to keep track of some indentation

        foreach ($job as $key => $value)
        {
            if (is_array($value))
            {
                $level++;
                $msg .= "$key data:\n";
                $msg .= $this->__multiarray( $value );
            }
            else
            {
                for ($x = 0; $x < $level; $x++)
                    $msg .= "  ";
                $msg .= "$key: $value\n";
            }
        }
        $level--;
        return $msg;
    }

    function construct_server()
    {
        global $dbname, $dbhost;
        global $udpport, $ipaddr, $ipa_ext;
        global $ipad_a, $ipae_a;

    }


}

class Submitter_2DSA extends Submitter
{



    function init_variables()
    {
        // Construct request[dataset]
        global $dbname;
        $this->prepare_datasets();
        $this->construct_post();
        global $_POST;
        $_POST = $this->post;
        $this->session['request'] = $this->datasets;
        $this->construct_cluster();
        $this->session['cluster'] = $this->cluster;

        global $_SESSION;
        $_SESSION = $this->session;
        $this->filenames = array();
    }

    function submit()
    {
        global $link;
        // start with simulating 2DSA_1.php
        $this->check_user();
        $this->check_instance();
        $files_ok = true;
        $HPCAnalysisRequestID = 0;
        $separate_datasets = $this->post['separate_datasets'];
        if ( $separate_datasets > 0 )
        {
            $dataset_count = count($this->datasets);
            $job_params = $this->post;
            $mgroup_count  = max( 1, $job_params['req_mgroupcount'] );
            $mc_iters      = max( 1, $job_params['mc_iterations'] );
            $reqds_count   = 50;              // Initial datasets per request
            if ( $mc_iters > 50 )
                $reqds_count   = 25;
            if ( $separate_datasets == 1 )
            {
                $reqds_count   = 1;
                $mgroup_count  = 1;
            }
            $groups        = (int)( $reqds_count / $mgroup_count );
            $groups        = max( 1, $groups );
            $reqds_count   = $mgroup_count * $groups;  // Multiple of PMGC
            $ds_remain     = $dataset_count;  // Remaining datasets
            $index         = 0;               // Input datasets index
            $kr            = 0;               // Output request index
            $missit_msg = "<br/>ds_remain=" . $ds_remain;

            priority( "2DSA", $dataset_count, $job_params );

            while ( $ds_remain > 0 )
            { // Loop to build HPC requests of composite jobs

                if ( ( $ds_remain - $reqds_count ) < $mgroup_count )
                    $reqds_count   = $ds_remain;
                else
                    $reqds_count   = min( $reqds_count, $ds_remain );
                $post = $this->post;
                $data = $this->datasets[ $index ];
                if (isset($data['simpoints']) && is_numeric($data['simpoints']) && $data['simpoints'] > 0)
                {
                    $post['simpoints-value'] = $data['simpoints'];
                    $post['simpoints'] = $data['simpoints'];
                    $post['sim_points'] = $data['simpoints'];
                }
                if (isset($data['band_volume']) && is_numeric($data['band_volume']) && $data['band_volume'] != -1)
                {
                    $post['band_volume-value'] = $data['band_volume'];
                    $post['band_volume'] = $data['band_volume'];
                }
                if (isset($data['radial_grid']) && is_numeric($data['radial_grid']) && $data['radial_grid'] != -1)
                {
                    $post['radial_grid'] = $data['radial_grid'];
                }
                if (isset($data['time_grid']) && is_numeric($data['time_grid']) && $data['time_grid'] != -1)
                {
                    $post['time_grid'] = $data['time_grid'];
                }
                $job = array(
                    'datasets' => $this->datasets,
                    'job_parameters' => $this->post
                );
                $HPCAnalysisRequestID = $this->write_HPC_to_DB( $job, $post );
                $job = array(
                    'datasets' => array( $data ),
                    'job_parameters' => $post
                );
                $filenames[ $kr ] = $this->write( $job, $post, $HPCAnalysisRequestID );
                if ( $filenames[ $kr ] === false )
                {
                    $missit_msg .= "<br/>composite=" . $data;
                    $missit_msg .= "<br/> kr=" . $kr;
                    $missit_msg .= "<br/> fnkr=" . $filenames[$kr];
                    $files_ok = false;
                }

                else
                { // Write the xml file content to the db
                    $xml_content = mysqli_real_escape_string( $link, file_get_contents( $filenames[ $kr ] ) );
                    $edit_filename = $data['edit'];
                    $experimentID  = $data['experimentID'];

                    $query  = "UPDATE HPCAnalysisRequest " .
                        "SET requestXMLfile = '$xml_content', " .
                        "experimentID = '$experimentID', " .
                        "editXMLFilename = '$edit_filename' " .
                        "WHERE HPCAnalysisRequestID = $HPCAnalysisRequestID ";
                    mysqli_query( $link, $query )
                    or die("Query failed : $query<br />\n" . mysqli_error($link));
                    $this->submitted_requests[] = $HPCAnalysisRequestID;
                }
                $this->filenames = $filenames;
                $index        += $reqds_count;
                $ds_remain    -= $reqds_count;
                $kr++;
            }
        }
        else
        { // Multiple datasets and global
            priority( "2DSA-GF", count($this->datasets), $this->post );
            $job = array(
                'datasets' => $this->datasets,
                'job_parameters' => $this->post
            );
            $HPCAnalysisRequestID = $this->write_HPC_to_DB($job, $this->post);
            $filenames[ 0 ] = $this->write( $job, $this->post, $HPCAnalysisRequestID );
            $missit_msg = '';
            if ( $filenames[ 0 ] === false )
            {
                $missit_msg = "<br/>filenames[0]=" . $filenames[0];
                $files_ok = false;
            }

            else if ( $filenames[ 0 ] === '2DSA-IT-MISSING' )
            {
                $files_ok = false;
                $missit_msg = "<br/><b>Global Fit without all needed 2DSA-IT models</b/>";
            }

            else
            {
                // Write the xml file content to the db
                $xml_content = mysqli_real_escape_string( $link, file_get_contents( $filenames[ 0 ] ) );
                $edit_filename = $job['datasets'][0]['edit'];

                $query  = "UPDATE HPCAnalysisRequest " .
                    "SET requestXMLfile = '$xml_content', " .
                    "editXMLFilename = '$edit_filename' " .
                    "WHERE HPCAnalysisRequestID = $HPCAnalysisRequestID ";
                mysqli_query( $link, $query )
                or die("Query failed : $query<br />\n" . mysqli_error($link));
                $this->submitted_requests[] = $HPCAnalysisRequestID;
            }
            $this->filenames = $filenames;
        }
        global $dbname, $dbhost;
        global $udpport, $ipaddr, $ipa_ext;
        global $ipad_a, $ipae_a;
        if ( $files_ok )
        {
            $output_msg = '';

            // EXEC COMMAND FOR TIGRE
            if ( isset($this->cluster) )
            {
                $cluster     = $this->cluster['shortname'];

                unset( $_SESSION['cluster'] );
                global $global_cluster_details;
                if ( isset( $global_cluster_details )
                    && is_array( $global_cluster_details )
                    && array_key_exists( $cluster, $global_cluster_details )
                    && array_key_exists( 'airavata', $global_cluster_details[$cluster] ) ) {
                    if ( $global_cluster_details[$cluster]['airavata' ] ) {
                        $job = new submit_airavata();
                    } else {
                        $job = new submit_local();
                    }
                } else {
                    error_log( "$cluster not properly setup\n" );
                    $msg = "<br /><span class='message'>Configuration error: Unsupported cluster $cluster</span><br />\n";
                    $this->result[] = $msg;
                    return;
                }

                $save_cwd = getcwd();         // So we can come back to the current
                // working directory later

                foreach ( $filenames as $filename )
                {


                    chdir( dirname( $filename ) );

                    $job-> clear();

                    $job-> parse_input( basename( $filename ) );
                    if ( ! DEBUG ) {
                        $job->submit();
                    }
                    $retval = $job->get_messages();

                    if ( ! empty( $retval ) )
                    {
                        $output_msg .= "<br /><span class='message'>Message from the queue...</span><br />\n" .
                            print_r( $retval, true ) . " <br />\n";
                    }
                    else {
                        $output_msg .= "<br /><span class='message'>Message from the queue...filename=$filename</span><br/>\n";
                        $output_msg .= "</pre>\n";
                        $this->errors[] = "Error submitting job to $cluster";
                        return;
                    }
                }

                $job->close_transport();   // Will be dummy for newer classes
                chdir( $save_cwd );
            }
            $output_msg .= "</pre>\n";
            $this->result[] = $output_msg;
        }

        else
        {
            $output_msg = <<<HTML
  Thank you, there have been one or more problems writing the various files necessary
  for job submission. Please contact your system administrator.
  $missit_msg

HTML;
            $this->result[] = $output_msg;
        }

    }

    function test()
    {
        // TODO: Implement test() method.
    }

    function create_HPC_job_params($HPCAnalysisRequestID, $params)
    {
        global $link;
        $query  = "INSERT INTO 2DSA_Settings SET " .
            "HPCAnalysisRequestID = $HPCAnalysisRequestID, " .
            "s_min                = {$params['s_value_min']},            " .
            "s_max                = {$params['s_value_max']},            " .
            "s_resolution         = {$params['s_grid_points']},    " .
            "ff0_min              = {$params['ff0_min']},          " .
            "ff0_max              = {$params['ff0_max']},          " .
            "ff0_resolution       = {$params['ff0_grid_points']},  " .
            "uniform_grid         = {$params['uniform_grid']},     " .
            "mc_iterations        = {$params['mc_iterations']}, " .
            "tinoise_option       = {$params['tinoise_option']},   " .
            "meniscus_range       = {$params['meniscus_range']},   " .
            "meniscus_points      = {$params['meniscus_points']},  " .
            "max_iterations       = {$params['max_iterations']}, " .
            "rinoise_option       = {$params['rinoise_option']}    ";

        mysqli_query( $link, $query )
        or die( "Query failed : $query<br />" . mysqli_error($link));
    }

    function construct_payload()
    {
        $payload_prep = array();
        $payload_prep['method'] = '2DSA';
        if ((int)$this->input['separate_datasets']??1>0)
        {
            $payload_prep['separate_datasets'] = (int)$this->input['separate_datasets'];
            $payload_prep['datasetCount'] = sizeof( $this->input['datasets']);
        }
        else
        {
            $payload_prep['separate_datasets'] = 0;
            $payload_prep['datasetCount'] = 1;
        }
        if ( $payload_prep['datasetCount'] > 4 ) {
            $this->cluster['queue'] = 'ngenseq';
        }
        $this->session['datasetCount'] = $payload_prep['datasetCount'];

    }

    function construct_post()
    {
        $post = array();
        // create post
        $post['TIGRE'] = 'Submit';
        $post['next'] = 'Submit';

        $post['cluster'] = $this->input['clusternode']??'';
        $post['s_value_min'] = $this->input['job_parameters']['s_min']??1;
        $post['s_value_max'] = $this->input['job_parameters']['s_max']??10;
        $post['s_min'] = $this->input['job_parameters']['s_min']??1;
        $post['s_max'] = $this->input['job_parameters']['s_max']??10;
        $post['s_grid_points'] = $this->input['job_parameters']['s_grid_points']??64;
        $post['ff0_min'] = $this->input['job_parameters']['k_min']??1;
        $post['ff0_max'] = $this->input['job_parameters']['k_max']??4;
        $post['ff0_grid_points'] = $this->input['job_parameters']['k_grid_points']??64;
        $grid = $this->compute_uniform_grid_repetitions($post['s_grid_points'], $post['ff0_grid_points']);
        $post['uniform_grid'] = $grid['grid_repetitions'];
        $post['s_grid_points'] = $grid['grid_points_s'];
        $post['ff0_grid_points'] = $grid['grid_points_k'];
        $post['mc_iterations'] = $this->input['job_parameters']['mc_iter']??1;
        $post['tinoise_option'] = (int)$this->input['job_parameters']['ti_noise']??0;
        $post['rinoise_option'] = (int)$this->input['job_parameters']['ri_noise']??0;

        if ( !isset( $this->input['job_parameters']['fit_mb']) || $this->input['job_parameters']['fit_mb'] == 'None' )
        {
            $post['fit_mb_select'] = 0;
        }
        elseif ( $this->input['job_parameters']['fit_mb'] == 'Meniscus' )
        {
            $post['fit_mb_select'] = 1;
        }
        elseif ( $this->input['job_parameters']['fit_mb'] == 'Bottom' ) {
            $post['fit_mb_select'] = 2;
        }
        elseif ( $this->input['job_parameters']['fit_mb'] == 'Both' )
        {
            $post['fit_mb_select'] = 3;
        }
        else {
            $post['fit_mb_select'] = 0;
        }
        $post['meniscus_range'] = $this->input['job_parameters']['fit_mb_range']??0.03;
        $post['meniscus_points'] = $this->input['job_parameters']['fit_mb_points']??11;
        if ($post['fit_mb_select'] == 0)
        {
            $post['meniscus_range'] = 0.0;
            $post['meniscus_points'] = 1;
        }
        $post['iterations_option'] = (int)$this->input['job_parameters']['iterative']??0;
        $post['max_iterations'] = $this->input['job_parameters']['max_iterations']??10;
        if ($post['iterations_option'] == 0){
            $post['max_iterations'] = 1;
        }
        $post['debug_level-value'] = $this->input['job_parameters']['debug_level']??0;
        $post['debug_text-value'] = $this->input['job_parameters']['debug_text']??'';
        $post['debug_level'] = $this->input['job_parameters']['debug_level']??0;
        $post['debug_text'] = $this->input['job_parameters']['debug_text']??'';
        $post['simpoints-value'] = $this->input['job_parameters']['sim_points']??200;
        $post['band_volume-value'] = $this->input['job_parameters']['band_volume']??0.001;
        $post['simpoints'] = $this->input['job_parameters']['sim_points']??200;
        $post['band_volume'] = $this->input['job_parameters']['band_volume']??0.001;
        $post['radial_grid'] = $this->parse_radial_grid($this->input['job_parameters']['radial_grid']??0);
        $post['time_grid'] = $this->parse_time_grid($this->input['job_parameters']['time_grid']??0);
        if ( isset( $this->input['job_parameters']['req_mgroupcount'] ) )
        {
            if ( $post['mc_iterations'] > 1 )
                $post['req_mgroupcount'] = $this->input['job_parameters']['req_mgroupcount'];
            else if ( count($this->datasets) > 1 )
                $post['req_mgroupcount'] = $this->input['job_parameters']['req_mgroupcount'];
            else
                $post['req_mgroupcount'] = 1;
        }

        else {
            $post['req_mgroupcount'] = 1;
        }
        $analType = '2DSA';
        $post['method'] = $analType;
        if ( count($this->datasets) > 1  && $this->session['separate_datasets'] == 0 )
            $analType            .= '-GL';
        if ( $post['fit_mb_select'] == 1 )
            $analType            .= '-FM';
        else if ( $post['fit_mb_select'] == 2 )
            $analType            .= '-FB';
        else if ( $post['fit_mb_select'] == 3 )
            $analType            .= '-FMB';
        if ( $post['max_iterations' ] > 1 )
            $analType            .= '-IT';
        if ( $post['mc_iterations'  ] > 1 )
            $analType            .= '-MC';
        $post['analType'] = $analType;
        $post['separate_datasets'] = $this->session['separate_datasets'];
        $this->post = $post;
    }

    function construct_params()
    {
        $job_parameters = array();
        // create

    }

    function prepare_submit()
    {
        $num_datasets['datasetCount'] = sizeof( $this->datasets);
        $_SESSION['datasetCount'] = $num_datasets;
        $this->session['datasetCount'] = $num_datasets;
        $this->payload->clear();
        $this->payload->add('cluster', $this->cluster);
        for ( $i = 0; $i < $num_datasets; $i++ )
        {
            // Get post newly
            $this->construct_post();
            $this->post['dataset_id'] = $i;
            // overwrite advanced analysis parameter if needed
            $data = $this->datasets[$i];
            if (isset($data['simpoints']) && is_numeric($data['simpoints']) && $data['simpoints'] > 0)
            {
                $this->post['simpoints-value'] = $data['simpoints'];
            }
            if (isset($data['band_volume']) && is_numeric($data['band_volume']) && $data['band_volume'] != -1)
            {
                $this->post['band_volume-value'] = $data['band_volume'];
            }
            if (isset($data['radial_grid']) && is_numeric($data['radial_grid']) && $data['radial_grid'] != -1)
            {
                $this->post['radial_grid'] = $data['radial_grid'];
            }
            if (isset($data['time_grid']) && is_numeric($data['time_grid']) && $data['time_grid'] != -1)
            {
                $this->post['time_grid'] = $data['time_grid'];
            }
            global $_POST;
            $_POST = $this->post;
            $this->payload->restore();
            $this->payload->acquirePostedData( $i, $num_datasets );
            $this->payload->save();
            // update all copies of the data
            $new_data = $this->payload->get('dataset')[$i];
            $this->datasets = $new_data;
            $this->session['request'][$i] = $new_data;
            $_SESSION['request'][$i] = $new_data;
            // update job parameters
            $this->parameters = $this->payload->get('job_parameters');
            $this->session['job_parameters'] = $this->parameters;
            $_SESSION['job_parameters'] = $this->parameters;

        }
    }

    function writeJobParameters( $xml, $parameters )
    {
        $xml->startElement( 'jobParameters' );
        $xml->startElement( 's_min' );
        $xml->writeAttribute( 'value', $parameters['s_min'] );
        $xml->endElement(); // s_min
        $xml->startElement( 's_max' );
        $xml->writeAttribute( 'value', $parameters['s_max'] );
        $xml->endElement(); // s_max
        $xml->startElement( 's_resolution' );
        $xml->writeAttribute( 'value', $parameters['s_grid_points'] /
            $parameters['uniform_grid']  );
        $xml->endElement(); // old-style s_resolution
        $xml->startElement( 's_grid_points' );
        $xml->writeAttribute( 'value', $parameters['s_grid_points'] );
        $xml->endElement(); // s_grid_points
        $xml->startElement( 'ff0_min' );
        $xml->writeAttribute( 'value', $parameters['ff0_min'] );
        $xml->endElement(); // ff0_min
        $xml->startElement( 'ff0_max' );
        $xml->writeAttribute( 'value', $parameters['ff0_max'] );
        $xml->endElement(); // ff0_max
        $xml->startElement( 'ff0_resolution' );
        $xml->writeAttribute( 'value', $parameters['ff0_grid_points'] /
            $parameters['uniform_grid']    );
        $xml->endElement(); // old-style ff0_resolution
        $xml->startElement( 'ff0_grid_points' );
        $xml->writeAttribute( 'value', $parameters['ff0_grid_points'] );
        $xml->endElement(); // ff0_grid_points
        $xml->startElement( 'uniform_grid' );
        $xml->writeAttribute( 'value', $parameters['uniform_grid'] );
        $xml->endElement(); // uniform_grid
        $xml->startElement( 'mc_iterations' );
        $xml->writeAttribute( 'value', $parameters['mc_iterations'] );
        $xml->endElement(); // mc_iterations
        $xml->startElement( 'req_mgroupcount' );
        $xml->writeAttribute( 'value', $parameters['req_mgroupcount'] );
        $xml->endElement(); // req_mgroupcount
        $xml->startElement( 'tinoise_option' );
        $xml->writeAttribute( 'value', $parameters['tinoise_option'] );
        $xml->endElement(); // tinoise_option
        $xml->startElement( 'rinoise_option' );
        $xml->writeAttribute( 'value', $parameters['rinoise_option'] );
        $xml->endElement(); // rinoise_option
        $xml->startElement( 'fit_mb_select' );
        $xml->writeAttribute( 'value', $parameters['fit_mb_select'] );
        $xml->endElement(); // fit_mb_select
        $xml->startElement( 'meniscus_range' );
        $xml->writeAttribute( 'value', $parameters['meniscus_range'] );
//        $xml->writeAttribute( 'MRposted', $parameters['MR_posted'] );
//        $xml->writeAttribute( 'MRpostval', $parameters['MR_postval'] );
        $xml->endElement(); // meniscus_range
        $xml->startElement( 'meniscus_points' );
        $xml->writeAttribute( 'value', $parameters['meniscus_points'] );
        $xml->endElement(); // meniscus_points
        $xml->startElement( 'max_iterations' );
        $xml->writeAttribute( 'value', $parameters['max_iterations'] );
        $xml->endElement(); // max_iterations
        $xml->startElement( 'debug_timings' );
        $xml->writeAttribute( 'value', $parameters['debug_timings']??'on' );
        $xml->endElement(); // debug_timings
        $xml->startElement( 'debug_level' );
        $xml->writeAttribute( 'value', $parameters['debug_level'] );
        $xml->endElement(); // debug_level
        $xml->startElement( 'debug_text' );
        $xml->writeAttribute( 'value', $parameters['debug_text'] );
        $xml->endElement(); // debug_text
        $xml->endElement(); // jobParameters
    }

}

