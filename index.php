<?php
/**
 * MyBounty v0.0.0.2 (https://secator.com/)
 * Copyright 2018, secator
 * Licensed under MIT (http://en.wikipedia.org/wiki/MIT_License)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

define('PID', sys_get_temp_dir() . '/' . basename(__FILE__) . '.pid');
define('SYNC', file_exists(PID));

if (isset($_POST['sync'])) {
	echo json_encode(array('sync' => SYNC));
	exit;
}

define('DB', __DIR__ . '/db/');
define('DB_H1', DB . 'hackerone/');
define('DB_OTHER', DB . 'other/');
define('DB_TIME', DB . 'time/');


$time = array();
function timer($timestamp, &$time) {
	$date = date('Y-m', strtotime($timestamp));
	if (empty($time[$date]) && file_exists(DB_TIME . $date)) {
		$time[date('Y-m-t', strtotime($timestamp))]['hours'] = (int)file_get_contents(DB_TIME . $date);
	}
}

$bugs_h1 = glob(DB_H1 . "*");
define('FIRST_H1', (count($bugs_h1) === 0));

$bugs_other = glob(DB_OTHER . "*");

if (isset($_POST['cookie'])) {
	
	touch(PID);
	
	include 'HackerOne.php';
	
	$h1   = new HackerOne($_POST['email'], $_POST['password'], $_POST['cookie']);
	$user = $h1->current_user();
	
	if (empty($user)) {
		unlink(PID);
		echo 'error auth';
		exit;
	}
	
	$sort_direction = FIRST_H1 ? 'ascending' : 'descending';
	
	$page = 0;
	
	do {
		$page++;
		$bugs = $h1->bugs($sort_direction, $page);
		if (!empty($bugs['bugs'])) {
			foreach ($bugs['bugs'] as $bug) {
				$file = DB_H1 . $bug['id'] . '.json';
				$time = file_exists($file) ? filemtime($file) : 0;
				if ($time < strtotime($bug['latest_activity'])) {
					if ($report = $h1->report($bug['id'])) {
						file_put_contents($file, $report);
					}
					sleep(1);
				} else {
					$page = $bugs['pages'];
					break;
				}
			}
		}
	} while (!empty($bugs['pages']) && $bugs['pages'] > $page);
	
	unlink(PID);
	exit;
}

$activities = array(
	'Activities::Created'              => 'Created',
	'Activities::BugTriaged'           => 'Triaged',
	'Activities::Comment'              => 'Comment',
	'Activities::BountyAwarded'        => 'Bounty',
	'Activities::SwagAwarded'          => 'Swag',
	'Activities::BugClosed'            => 'Closed',
	'Activities::BugReopened'          => 'Reopened',
	'Activities::NotEligibleForBounty' => 'Not Eligible',
	'Activities::BugNeedsMoreInfo'     => 'Needs more info',
	'Activities::BugResolved'          => 'Resolved',
	'Activities::BugInformative'       => 'Informative',
	'Activities::BugNotApplicable'     => 'N/A',
	'Activities::BugDuplicate'         => 'Duplicate',
	'Activities::BugSpam'              => 'Spam',
	'Activities::BugNew'               => 'New',
	'Activities::ReportBecamePublic'   => 'Public',
);

$states = array_fill_keys($activities, 0);

$bounty = array(
	'Bounty' => 0,
	'Bonus'  => 0,
	'Swag'   => 0,
);

$periods = array(
	'months' => 'Y-m-t',
	'years'  => 'Y-12-31',
	'days'   => 'Y-m-d',
);

$programs = array('all' => 0);

$charts             = array();
$charts['activity'] = array_fill_keys(array_keys($charts), array());
$charts['bounty']   = $charts['activity'];

$data = array();

foreach ($bugs_h1 as $bug) {
	
	$bug = json_decode(file_get_contents($bug), true);
	
	if (empty($bug['activities'])) {
		$bug['activities'] = array();
	}
	
	array_unshift($bug['activities'], array(
		'type'       => 'Activities::Created',
		'created_at' => $bug['created_at'],
	));
	
	foreach (array('all',
	               $bug['team']['handle']) as $program) {
		
		if (!isset($programs[ $program ])) {
			$programs[ $program ] = 0;
		}
		$programs[ $program ]++;
		
		if (empty($data[ $program ])) {
			$data[ $program ] = $charts;
		}
		
		for ($i = 0, $ic = count($bug['activities']); $i < $ic; $i++) {
			
			timer($bug['activities'][ $i ]['created_at'], $time);
			$created_at = strtotime($bug['activities'][ $i ]['created_at']);
			
			foreach ($periods as $period => $format) {
				
				if (!isset($activities[ $bug['activities'][ $i ]['type'] ])) {
					continue;
				}
				
				$type = $activities[ $bug['activities'][ $i ]['type'] ];
				$date = date($format, $created_at);
				
				if (empty($data[ $program ]['activity'][ $period ][ $date ])) {
					$data[ $program ]['activity'][ $period ][ $date ] = $states;
				}
				$data[ $program ]['activity'][ $period ][ $date ][ $type ]++;
				
				if (isset($bounty[ $type ])) {
					
					if (empty($data[ $program ]['bounty'][ $period ][ $date ])) {
						$data[ $program ]['bounty'][ $period ][ $date ] = $bounty;
					}
					
					if (!empty($bug['activities'][ $i ]['bounty_amount'])) {
						$data[ $program ]['bounty'][ $period ][ $date ]['Bounty'] += $bug['activities'][ $i ]['bounty_amount'];
					}
					
					if (!empty($bug['activities'][ $i ]['bonus_amount'])) {
						$data[ $program ]['bounty'][ $period ][ $date ]['Bonus'] += $bug['activities'][ $i ]['bonus_amount'];
					}
					
					if ($type == 'Swag') {
						$data[ $program ]['bounty'][ $period ][ $date ][ $type ]++;
					}
				}
			}
		}
		
	}
}

foreach ($bugs_other as $bug) {
	
	if (basename($bug) == 'example.json') {
		continue;
	}
	
	$bug = json_decode(file_get_contents($bug), true);
	
	if (empty($bug['created_at'])) {
		continue;
	}
	
	$created_at = strtotime($bug['created_at']);
	
	foreach (array('all',
	               $bug['program']) as $program) {
		
		timer($bug['created_at'], $time);
		
		if (!isset($programs[ $program ])) {
			$programs[ $program ] = 0;
		}
		$programs[ $program ]++;
		
		if (empty($data[ $program ])) {
			$data[ $program ] = $charts;
		}
		
		foreach ($periods as $period => $format) {
			
			if (empty($bug['state'])) {
				$bug['state'] = 'Resolved';
			}
			
			if (!isset($states[ $bug['state'] ])) {
				continue;
			}
			
			if (empty($bug['triaged'])) {
				$bug['triaged'] = $bug['created_at'];
			}
			if (empty($bug['bounty_date'])) {
				$bug['bounty_date'] = $bug['created_at'];
			}
			
			$type = $bug['state'];
			$date = date($format, $created_at);
			
			if (empty($data[ $program ]['activity'][ $period ][ $date ])) {
				$data[ $program ]['activity'][ $period ][ $date ] = $states;
			}
			
			$data[ $program ]['activity'][ $period ][ $date ][ $type ]++;
			
			$data[ $program ]['activity'][ $period ][ $date ][ 'Created' ]++;
			
			if (!empty($bug['triaged'])) {
				$data[ $program ]['activity'][ $period ][ $date ][ 'Triaged' ]++;
			}
			if (!empty($bug['bounty_date'])) {
				$data[ $program ]['activity'][ $period ][ $date ][ 'Bounty' ]++;
			}
			
			if (empty($data[ $program ]['bounty'][ $period ][ $date ])) {
				$data[ $program ]['bounty'][ $period ][ $date ] = $bounty;
			}
			
			if (!empty($bug['bounty'])) {
				$data[ $program ]['bounty'][ $period ][ $date ]['Bounty'] += $bug['bounty'];
			}
			
			if (!empty($bug['bonus'])) {
				$data[ $program ]['bounty'][ $period ][ $date ]['Bonus'] += $bug['bonus'];
			}
			
			if (!empty($bug['swag'])) {
				$data[ $program ]['bounty'][ $period ][ $date ]['Swag']++;
			}
		}
	}
}

arsort($programs);

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<title>MyBounty</title>
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/css/bootstrap.min.css"
	      crossorigin="anonymous">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/c3/0.6.4/c3.min.css"
	      crossorigin="anonymous">
	<script src="https://code.jquery.com/jquery-3.2.1.min.js"
	        crossorigin="anonymous"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0/js/bootstrap.min.js"
	        crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/5.5.0/d3.min.js" crossorigin="anonymous"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/c3/0.6.4/c3.min.js" crossorigin="anonymous"></script>
</head>
<body>

<div class="container-fluid">
	<button type="button" class="btn btn-primary float-right" data-toggle="modal" data-target="#auth" id="sync">Sync
	</button>
	<div id="charts">
		<div></div>
	</div>
</div>

<div class="modal fade" id="auth" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<form class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="exampleModalLabel">Auth</h5>
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
			</div>
			<div class="modal-body">
				<ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
					<li class="nav-item">
						<a class="nav-link active" id="pills-home-tab" data-toggle="pill" href="#pills-cookie" role="tab" aria-controls="pills-home" aria-selected="true">
							Cookie
						</a>
					</li>
					<li class="nav-item">
						<a class="nav-link" id="pills-profile-tab" data-toggle="pill" href="#pills-email" role="tab" aria-controls="pills-profile" aria-selected="false">
							Email/Password
						</a>
					</li>
				</ul>
				<div class="tab-content" id="pills-tabContent">
					<div class="tab-pane fade show active" id="pills-cookie" role="tabpanel" aria-labelledby="pills-home-tab">
						<div class="form-group">
							<input type="text" name="cookie" class="form-control" id="InputCookie"
							       placeholder="Cookie" autocomplete="on">
						</div>
					</div>
					<div class="tab-pane fade" id="pills-email" role="tabpanel" aria-labelledby="pills-profile-tab">
						<div class="form-group">
							<input type="email" name="email" class="form-control" id="InputEmail"
							       placeholder="Email" autocomplete="on">
						</div>
						<div class="form-group">
							<input type="password" name="password" class="form-control" id="InputPassword"
							       placeholder="Password" autocomplete="on">
						</div>
					</div>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
				<button type="submit" class="btn btn-primary">Auth</button>
			</div>
		</form>
	</div>
</div>
<script>

    $(function () {

        function sync_check() {

            var bar = 'progress-bar progress-bar-striped progress-bar-animated',
                sync = $('#sync');

            sync.addClass(bar).attr('disabled', true);

            $.post(location.href, {sync: 1}, function (data) {
                var d = $.parseJSON(data);
                if (d['sync']) {
                    setTimeout(sync_check, 1000 * 2);
                } else {
                    sync.removeClass(bar).attr('disabled', false);
                }
            });
        };
		
		<?=SYNC ? "sync_check();" : ""?>

        $('#auth form').bind('submit', function (e) {
            e.preventDefault();

            $('#auth').modal('hide');

            $.post(location.href, $(this).serialize(), function (data) {});

            sync_check();
        });
        
        var charts_render = function (type, data) {

            console.log(data);
            var columns, date, col, id;
            
            columns = [];

            id = 'chart_' + type + '';
            $('> div', charts).append(
                '<h1>' + type + '</h1>' +
                '<div id="' + id + '"></div>'
            );

            for (date in data) {
                if (columns['x'] === undefined) {
                    columns['x'] = ['x'];
                }
                columns['x'].push(date);
                for (col in data[date]) {
                    if (columns[col] === undefined) {
                        columns[col] = [col];
                    }
                    columns[col].push(data[date][col] || null);
                }
            }

            c3.generate({
                bindto: '#' + id,
                data: {
                    x: 'x',
                    columns: Object.values(columns)
                },
                axis: {
                    x: {
                        type: 'timeseries',
                        tick: {
                            format: '%Y-%m-%d'
                        }
                    }
                },
                zoom: {
                    enabled: true
                }
            });
        };

        var data = <?=json_encode($data)?>,
            programs = <?=json_encode($programs)?>,
            programs_select = $('<select/>'),
            periods = <?=json_encode(array_keys($periods))?>,
            periods_select = $('<select/>'),
            charts = $('#charts');

        for (var i = 0, ic = periods.length; i < ic; i++) {
            periods_select.append('<option>' + periods[i] + '</option>');
        }
        charts.prepend(periods_select);

        for (var a in programs) {
            programs_select.append('<option value="' + a + '">' + a + ' (' + programs[a] + ')</option>');
        }
        charts.prepend(programs_select);

        periods_select.bind('change', function () {

            var value = $(this).val(),
                program = programs_select.val(),
                type;

            $('> div', charts).html('');

            for (type in data[program]) {
                charts_render(type, data[program][type][value]);
            }
        }).trigger('change');

        programs_select.bind('change', function () {
            periods_select.trigger('change');
        });

        var time = <?=json_encode($time)?>;
	    charts_render('time', time);

    });
</script>
</body>
</html>
