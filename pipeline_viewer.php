<?php
/*
 * pipeline_viewer.php
 *
 * Analysis pipelines of the logged-in user (all pipelines for admins):
 * list, stages with their state, and actions on single stages
 * (retry, cancel, skip, unskip) or the whole pipeline (cancel).
 * Scheduling itself is done by submitctl.php (us3lims_gridctl).
 */
include_once 'checkinstance.php';

if ( ($_SESSION['userlevel'] != 2) &&
     ($_SESSION['userlevel'] != 3) &&
     ($_SESSION['userlevel'] != 4) &&
     ($_SESSION['userlevel'] != 5) )    // only data analyst and up
{
  header('Location: index.php');
  exit();
}

include_once 'config.php';
include_once 'db.php';
include_once 'lib/pipeline.php';

$page_title = 'Analysis Pipelines';
$css        = 'css/queue_viewer.css';
$h = function( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); };

if ( ! pipeline_available( $link ) )
{
  include 'header.php';
  echo "<div id='content'><h1 class='title'>$page_title</h1>\n" .
       "<p class='message'>Analysis pipelines are not available on this instance.</p></div>\n";
  include 'footer.php';
  exit();
}

$pipelineID = intval( $_REQUEST['pipelineID'] ?? 0 );
$pipeline   = $pipelineID ? pl_get_pipeline( $link, $dbname, $pipelineID ) : null;
if ( $pipelineID && ( ! $pipeline || ! pipeline_authorized( $pipeline ) ) )
{
  $_SESSION['pipeline_message'] = "Pipeline $pipelineID not found.";
  header( "Location: pipeline_viewer.php" );
  exit();
}

// --- actions (POST, then redirect) ---
if ( $_SERVER['REQUEST_METHOD'] == 'POST' && $pipeline )
{
  $action = $_POST['action'] ?? '';
  try
  {
    if ( $action == 'cancel_pipeline' )
      $r = pl_cancel_pipeline( $link, $dbname, $pipelineID );
    else
      $r = pl_row_action( $link, $dbname, $pipelineID, intval( $_POST['requestID'] ?? 0 ),
                          $action, intval( $_POST['revision'] ?? -1 ) );
    $_SESSION['pipeline_message'] = $r[1];
  }
  catch ( Exception $e )
  {
    $_SESSION['pipeline_message'] = "Action failed: " . $e->getMessage();
  }
  header( "Location: pipeline_viewer.php?pipelineID=$pipelineID" );
  exit();
}

$message = $_SESSION['pipeline_message'] ?? '';
unset( $_SESSION['pipeline_message'] );

include 'header.php';
echo "<div id='content'>\n<h1 class='title'>$page_title</h1>\n";
if ( $message !== '' )
  echo "<p class='message' id='pipeline_message'>" . $h( $message ) . "</p>\n";

$status_colors = [ 'FINISHED' => 'green', 'SKIPPED' => 'gray', 'FAILED' => 'red', 'CANCELED' => 'red',
                   'BLOCKED' => 'DarkOrange', 'PENDING' => 'black' ];
$status_html = function( $status ) use ( $h, $status_colors ) {
  $s = strtoupper( $status );
  $c = $status_colors[ $s ] ?? 'blue';      // ready / submitted / running / ...
  return "<span style='color:$c;font-weight:bold'>" . $h( $s ) . "</span>";
};

if ( ! $pipeline )
{
  // --- list ---
  $list = pl_list_pipelines( $link, $dbname, $_SESSION['userlevel'] >= 4 ? null : intval( $_SESSION['id'] ) );
  if ( $_SESSION['userlevel'] < 4 && intval( $_SESSION['loginID'] ) != intval( $_SESSION['id'] ) )
    $list = array_merge( $list, pl_list_pipelines( $link, $dbname, intval( $_SESSION['loginID'] ) ) );

  echo "<p><a href='queue_setup_1.php'>Set up a new pipeline</a> (queue setup &rarr; Setup Analysis Pipeline)</p>\n";
  if ( ! count( $list ) )
    echo "<p>No analysis pipelines.</p>\n";
  else
  {
    echo "<table class='pipeline' id='pipeline_list'><tr><th>ID</th><th>Name</th><th>Owner</th><th>Created</th>" .
         "<th>Status</th><th>Stages</th></tr>\n";
    foreach ( $list as $p )
    {
      $counts = [];
      foreach ( $p['counts'] as $s => $n )
        $counts[] = "$n " . strtolower( $s );
      $attention = ( $p['status'] == 'ACTIVE' && ( isset( $p['counts']['FAILED'] ) || isset( $p['counts']['BLOCKED'] ) ) )
                 ? " <span style='color:red'>(needs attention)</span>" : "";
      echo "<tr><td>{$p['pipelineID']}</td>" .
           "<td><a href='pipeline_viewer.php?pipelineID={$p['pipelineID']}'>" . $h( $p['name'] ) . "</a></td>" .
           "<td>" . $h( $p['owner'] ) . "</td><td>" . $h( $p['createTime'] ) . "</td>" .
           "<td>" . $status_html( $p['status'] ) . "$attention</td>" .
           "<td>" . $h( implode( ', ', $counts ) ?: ( $p['status'] == 'ACTIVE' ? '' : 'archived' ) ) . "</td></tr>\n";
    }
    echo "</table>\n";
  }
}
else
{
  // --- one pipeline ---
  $rows = pl_get_pipeline_rows( $link, $dbname, $pipelineID );
  $name_of = function( $id ) use ( $rows ) {
    if ( ! isset( $rows[ $id ] ) )
      return "#$id";
    $r = $rows[ $id ];
    return ( $r['rowKind'] == 'group' ? 'group ' . $r['groupName'] : $r['tripleName'] ) . ' / ' . $r['stageKey'];
  };
  $active = $pipeline['status'] == 'ACTIVE';

  echo "<p><a href='pipeline_viewer.php'>All pipelines</a> | <a href='pipeline_viewer.php?pipelineID=$pipelineID'>Refresh</a></p>\n" .
       "<table class='pipeline'>" .
       "<tr><th>Pipeline</th><td>" . $h( $pipeline['name'] ) . " (ID $pipelineID)</td></tr>" .
       "<tr><th>Status</th><td id='pipeline_status'>" . $status_html( $pipeline['status'] ) . "</td></tr>" .
       "<tr><th>Cluster</th><td>" . $h( $pipeline['clusterDefault'] ) . "</td></tr>" .
       "<tr><th>Created</th><td>" . $h( $pipeline['createTime'] ) . "</td></tr></table>\n";

  if ( $active )
    echo "<form method='post' onsubmit=\"return confirm('Cancel all remaining stages of this pipeline?');\">" .
         "<input type='hidden' name='pipelineID' value='$pipelineID' />" .
         "<input type='hidden' name='action' value='cancel_pipeline' />" .
         "<p><input type='submit' id='cancel_pipeline' value='Cancel pipeline' /></p></form>\n";

  echo "<table class='pipeline' id='pipeline_stages'><tr><th>Dataset / group</th><th>Stage</th><th>Method</th><th>Status</th>" .
       "<th>Message</th><th>Waits for</th><th>Noise subtracted</th><th>HPC request</th><th>Results</th><th>Actions</th></tr>\n";
  foreach ( $rows as $id => $r )
  {
    $sj    = $r['sj'];
    $who   = $r['rowKind'] == 'group' ? 'group ' . $r['groupName'] . ' (' . implode( ', ', $sj['members'] ?? [] ) . ')'
                                      : $r['tripleName'];
    $after = implode( ', ', array_map( $name_of, $sj['after'] ?? [] ) );
    $nf    = $sj['noise_from'] ?? 'none';
    if ( is_array( $nf ) )
      $nf  = isset( $nf['requestID'] ) ? 'from ' . $name_of( $nf['requestID'] ) : "from members' stage " . $nf['member_stage'];
    else
      $nf  = $nf == 'queue_setup' ? 'queue setup selection' : 'none';
    $hpc   = $r['currentHPCARID'] ?: '';
    $res   = '';
    if ( is_array( $sj['outputs'] ?? null ) )
      $res = count( $sj['outputs']['models'] ?? [] ) . ' model(s), ' . count( $sj['outputs']['noises'] ?? [] ) . ' noise(s)';

    $actions = '';
    if ( $active && ! $r['archived'] )
    {
      $class = pl_status_class( $r['status'] );
      $acts  = [];
      if ( in_array( $class, [ 'failed', 'canceled' ] ) && empty( $r['currentGfacID'] ) ) $acts[] = [ 'retry', 'Retry' ];
      if ( $class == 'pending' )                                                         $acts[] = [ 'skip', 'Skip' ];
      if ( $class == 'skipped' )                                                         $acts[] = [ 'unskip', 'Run' ];
      if ( in_array( $class, [ 'pending', 'blocked', 'active' ] ) && strtoupper( $r['status'] ) != 'READY' )
                                                                                         $acts[] = [ 'cancel', 'Cancel' ];
      foreach ( $acts as $a )
        $actions .= "<form method='post' style='display:inline'>" .
                    "<input type='hidden' name='pipelineID' value='$pipelineID' />" .
                    "<input type='hidden' name='requestID' value='$id' />" .
                    "<input type='hidden' name='revision' value='" . intval( $sj['revision'] ?? 0 ) . "' />" .
                    "<input type='hidden' name='action' value='{$a[0]}' />" .
                    "<input type='submit' value='{$a[1]}' data-row='$id' data-act='{$a[0]}' /></form> ";
    }
    echo "<tr id='row_$id'><td>" . $h( $who ) . "</td><td>" . $h( $r['stageKey'] ) . "</td><td>" . $h( $sj['method'] ?? '' ) .
         "</td><td>" . $status_html( $r['status'] ) . "</td><td>" . $h( $r['statusMsg'] ) . "</td><td>" . $h( $after ?: '-' ) .
         "</td><td>" . $h( $nf ) . "</td><td>" . $h( $hpc ) . "</td><td>" . $h( $res ) . "</td><td>$actions</td></tr>\n";
  }
  echo "</table>\n" .
       "<p class='hint'>Stages start automatically. Blocked stages continue once the failed stage they wait for " .
       "is retried successfully. A pipeline with failed stages stays active until they are retried or the pipeline is canceled.</p>\n";
}
?>
<style>
  table.pipeline { border-collapse: collapse; margin: 0.5em 0; }
  table.pipeline th, table.pipeline td { border: 1px solid #ccc; padding: 2px 5px; text-align: left; vertical-align: top; }
  .hint { color: #555; font-size: 90%; }
</style>
</div>
<?php
include 'footer.php';
?>
