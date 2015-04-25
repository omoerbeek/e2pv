<?php
/*
 * Copyright (c) 2015 Otto Moerbeek <otto@drijf.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

// By default, $ignored array is empty
$ignored = array();

// See README.md for details on config.php
require_once 'config.php';

// In case LIFETIME is not defined in config.php, default to LIFETIME mode
if (!defined('LIFETIME'))
  define('LIFETIME', 1);
// In case EXTENDED is not defined in config.php, do not send state counts
if (!defined('EXTENDED'))
  define('EXTENDED', 0);
// In case AC is not defined in config.php, default to 0
if (!defined('AC'))
  define('AC', 0);

/*
 * Report a message
 */
function report($msg) {
  echo date('Ymd-H:i:s') . ' ' . $msg . PHP_EOL;
}

/*
 * Fatal error, likely a configuration issue
 */
function fatal($msg) {
  report($msg . ': ' . socket_strerror(socket_last_error()));
  exit(1);
}

// $otal is an array holding last received values per inverter, indexed by
// inverter id. Each value is an array of name => value mappings, where name is:
// TS, Energy, Power array, Temp, Volt, State
$total = array();
// When did we last send to PVOUtput?
$last = 0;
// When did we last send a reply back to the gateway?
$lastkeepalive = 0;

/*
 * Compute aggregate info to send to PVOutput
 * See http://pvoutput.org/help.html#api-addstatus
 */
function submit($total, $systemid, $apikey) {
  // Compute aggragated data: energy, power, avg temp avg volt
  // Power is avg power over the reporting interval
  $e = 0.0;
  $p = 0.0;
  $temp = 0.0;
  $volt = 0.0;
  $nonzerocount = 0;
  $okstatecount = 0;
  $otherstatecount = 0;

  foreach ($total as $t) {
    $e += $t['Energy'];
    $pp = 0;
    foreach ($t['Power'] as $x)
      $pp += $x;
    $p += (double)$pp / count($t['Power']);
    $temp += $t['Temperature'];

    if ($pp > 0) {
      $volt += $t['Volt'];
      $nonzerocount++;
    }

    switch ($t['State']) {
    case 0:  // normal, supplying to grid
    case 1:  // not enough light
    case 3:  // other low light condition
      $okstatecount++;
      break;
    default:
      $otherstatecount++;
      break;
   }
  }
  $temp /= count($total);
  if ($nonzerocount > 0)
    $volt /= $nonzerocount;
  $p = round($p);

  if (LIFETIME)
    report(sprintf('=> PVOutput (%s) v1=%dWh v2=%dW v5=%.1fC v6=%.1fV',
      count($total) == 1 ? $systemid : 'A', $e, $p, $temp, $volt));
  else
    report(sprintf('=> PVOutput (%s) v2=%dW v5=%.1fC v6=%.1fV',
      count($total) == 1 ? $systemid : 'A', $p, $temp, $volt));
  $time = time();
  $data = array('d' => strftime('%Y%m%d', $time),
    't' => strftime('%H:%M', $time),
    'v2' => $p,
    'v5' => $temp,
    'v6' => $volt
  );

  // Only send cummulative total energy in LIFETIME mode
  if (LIFETIME) {
    $data['v1'] = $e;
    $data['c1'] = 1;
  }
  if (EXTENDED) {
    report(sprintf('   v7=%d v8=%d v9=%d', $nonzerocount, $okstatecount,
      $otherstatecount));
    $data['v7'] = $nonzerocount;
    $data['v8'] = $okstatecount;
    $data['v9'] = $otherstatecount;
  }

  // We have all the data, prepare POST to PVOutput
  $headers = "Content-type: application/x-www-form-urlencoded\r\n" .
    'X-Pvoutput-Apikey: ' . $apikey . "\r\n" .
    'X-Pvoutput-SystemId: ' . $systemid . "\r\n";
  $url = 'http://pvoutput.org/service/r2/addstatus.jsp';
  
  $data = http_build_query($data, '', '&');
  $ctx = array('http' => array(
    'method' => 'POST',
    'header' => $headers,
    'content' => $data));
  $context = stream_context_create($ctx);
  $fp = fopen($url, 'r', false, $context);
  if (!$fp)
    report('POST failed, check your APIKEY=' . $apikey . ' and SYSTEMID=' .
      $systemid);
  else {
    $reply = fread($fp, 100);
    report('<= PVOutput ' . $reply);
    fclose($fp);
  }

  // Optionally, also to mysql
  if (MODE == 'AGGREGATE' && defined('MYSQLDB')) {
    $mvalues = array(
     'IDDec' => 0,
     'DCPower' => $p, 
     'DCCurrent' => 0,
     'Efficiency' => 0,
     'ACFreq' => 0,
     'ACVolt' => $volt,
     'Temperature' => $temp,
     'State' => 0
    );
    submit_mysql($mvalues, $e);
  }
}


/*
 * Read data from socket until a "\r" is seen
 */
$buf = '';
function reader($socket) {
  global $buf;
  $last_read = time();
  while (true) {
    $pos = strpos($buf, "\r");
    if ($pos === false) {
      $ret = @socket_recv($socket, $str, 128, 0);
      if ($ret === false || $ret == 0) {
        if ($last_read <= time() - 90)
          return false;
        sleep(3);
        continue;
      }
      $last_read = time();
      $buf .= $str;
      continue;
    } else {
      $str = substr($buf, 0, $pos + 1);
      $buf = substr($buf, $pos + 2);
      return $str;
    }
  }
}

/*
 * Submit data to MySQL
 */
$link = false;
function submit_mysql($v, $LifeWh) {
  global $link;

  if (!$link) {
    $link = mysqli_connect(MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDB,
      MYSQLPORT);
  }
  if (!$link) {
    report('Cannot connect to MySQL ' . mysqli_connect_error());
    return;
  }

  $query = 'INSERT INTO enecsys(' .
    'id, wh, dcpower, dccurrent, efficiency, acfreq, acvolt, temp, state) ' .
     'VALUES(%d, %d, %d, %f, %f, %d, %f, %f, %d)';
  $q = sprintf($query,
    mysqli_real_escape_string($link, $v['IDDec']),
    mysqli_real_escape_string($link, $LifeWh),
    mysqli_real_escape_string($link, $v['DCPower']),
    mysqli_real_escape_string($link, $v['DCCurrent']),
    mysqli_real_escape_string($link, $v['Efficiency']),
    mysqli_real_escape_string($link, $v['ACFreq']),
    mysqli_real_escape_string($link, $v['ACVolt']),
    mysqli_real_escape_string($link, $v['Temperature']),
    mysqli_real_escape_string($link, $v['State']));

  if (!mysqli_query($link, $q)) {
   report('MySQL insert failed: ' . mysqli_error($link));
   mysqli_close($link);
   $link = false;
  }
}

/*
 * Loop processing lines from the gatway
 */
function process($socket) {
  global $total, $last, $lastkeepalive, $systemid, $apikey, $ignored;

  while (true) {
    $str = reader($socket);
    if ($str === false) {
        return;
    }
    // Send a reply if the last reply is 200 seconds ago
    if ($lastkeepalive < time() - 200) {
      //echo 'write' . PHP_EOL;
      if (socket_write($socket, "0E0000000000cgAD83\r") === false)
        return;
      //echo 'write done' . PHP_EOL;
      $lastkeepalive = time();
    }
    $str = str_replace(array("\n", "\r"), "", $str);
    //report($str);

    // If the string contains WS, we're interested
    $pos = strpos($str, 'WS');
    if ($pos !== false) {
      $sub = substr($str, $pos + 3);
      // Standard translation of base64 over www
      $sub = str_replace(array('-', '_' , '*'), array('+', '/' ,'='), $sub);
      //report(strlen($sub) . ' ' . $sub);
      $bin = base64_decode($sub);
      // Incomplete? skip
      if (strlen($bin) != 42) {
        //report('Unexpected length ' . strlen($bin) . ' skip...');
        continue;
      }
      //echo bin2hex($bin) . PHP_EOL;
      $v = unpack('VIDDec/c18dummy/CState/nDCCurrent/nDCPower/' .
         'nEfficiency/cACFreq/nACVolt/cTemperature/nWh/nkWh', $bin);
      $id = $v['IDDec'];

      if (in_array($id, $ignored))
        continue;
      if (MODE == 'SPLIT' && !isset($systemid[$id])) {
        report('SPLIT MODE and inverter ' . $id . ' not in $systemid array');
        continue;
      }
      $v['DCCurrent'] *= 0.025;
      $v['Efficiency'] *= 0.001;
      $LifeWh = $v['kWh'] * 1000 + $v['Wh'];
      $ACPower = $v['DCPower'] * $v['Efficiency'];
      $DCVolt = $v['DCPower'] / $v['DCCurrent'];

      $time = time();
      // Clear stale entries (older than 1 hour)
      foreach ($total as $key => $t) {
        if ($total[$key]['TS'] < $time - 3600)
          unset($total[$key]);
      }

      // Record in $total indexed by id: cummulative energy
      $total[$id]['Energy'] = $LifeWh;
      // Record in $total, indexed by id: count, last 10 power values
      // volt and temp
      if (!isset($total[$id]['Power'])) {
        $total[$id]['Power'] = array();
      }
      // pop oldest value
      if (count($total[$id]['Power']) > 10)
        array_shift($total[$id]['Power']);
      $total[$id]['Power'][] = AC ? $ACPower : $v['DCPower'];
      $total[$id]['Volt'] = $v['ACVolt'];
      $total[$id]['Temperature'] = $v['Temperature'];
      $total[$id]['State'] = $v['State'];

      printf('%s DC=%3dW %5.2fV %4.2fA AC=%3dV %6.2fW E=%4.2f T=%2d ' .
        'S=%d L=%.3fkWh' .  PHP_EOL,
        $id, $v['DCPower'], $DCVolt, $v['DCCurrent'],
        $v['ACVolt'], $ACPower,
        $v['Efficiency'], $v['Temperature'], $v['State'],
        $LifeWh / 1000);

      if (defined('MYSQLDB'))
        submit_mysql($v, $LifeWh);

      if (MODE == 'SPLIT') {
        // time to report for this inverter?
        if (!isset($total[$id]['TS']) || $total[$id]['TS'] < $time - 540) {
          $key = isset($apikey[$id]) ? $apikey[$id] : APIKEY;
          submit(array($total[$id]), $systemid[$id], $key);
          $total[$id]['TS'] = $time;
        }
      } 
      // for AGGREGATE, only report if we have seen all inverters
      if (count($total) != IDCOUNT) {
        report('Expecing IDCOUNT=' . IDCOUNT . ' IDs, seen ' .
          count($total) . ' IDs');
      } elseif ($last < $time - 540) {
        submit($total, SYSTEMID, APIKEY);
        $last = $time;
      }
      if (MODE == 'AGGREGATE')
        $total[$id]['TS'] = $time;
    }
    
    return;
  }
}

/*
 * Setup a listening socket
 */
function setup() {
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if ($socket === false)
    fatal('socket_create');
  // SO_REUSEADDR to make fast restarting of script possible
  socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
  $ok = socket_bind($socket, '0.0.0.0', 5040);
  if (!$ok) 
    fatal('socket_bind');
  // backlog of 1, we do not serve multiple clients
  $ok = socket_listen($socket, 1);
  if (!$ok)
    fatal('socket_listen');
  return $socket;
}

/*
 * Loop accepting connections from the gatwway
 */
function loop($socket) {
  $errcount = 0;
  while (true) {
    $client = socket_accept($socket);
    if (!$client) {
      report('Socket_accept: ' . socket_strerror(socket_last_error()));
      if (++$errcount > 100)
        fatal('Too many socket_accept errors in a row');
      else
        continue;
    }
    $errcount = 0;
    if (!socket_set_nonblock($client))
      fatal('socket_set_nonblock');
    socket_getpeername($client, $peer);
    report('Accepted connection from ' . $peer);
    process($client);
    socket_close($client);
    report('Connection closed'); 
  }
}

if (isset($_SERVER['REQUEST_METHOD'])) {
  report('only command line');
  exit(1);
}

if (!defined('LIFETIME') || (LIFETIME !== 0 && LIFETIME !== 1)) {
  report('LIFETIME should be defined to 0 or 1');
  exit(1);
}
if (!defined('EXTENDED') || (EXTENDED !== 0 && EXTENDED !== 1)) {
  report('EXTENDED should be defined to 0 or 1');
}
if (!defined('MODE') || (MODE != 'SPLIT' && MODE != 'AGGREGATE')) {
  report('MODE should be \'SPLIT\' or \'AGGREGATE\'');
  exit(1);
}
if (!defined('AC') || (AC !== 0 && AC !== 1)) {
  report('AC should be defined to 0 or 1');
  exit(1);
}
if (MODE == 'SPLIT' && count($systemid) != IDCOUNT) {
  report('In SPLIT mode, define IDCOUNT systemid mappings');
  exit(1);
}
  
$socket = setup();
loop($socket);
socket_close($socket);

?>
