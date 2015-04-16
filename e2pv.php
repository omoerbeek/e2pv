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

require_once 'config.php';
// Example to put in config.php:
// define('IDCOUNT', 4);
// define('APIKEY', 'PVOutput hex api key');
// define('SYSTEMID', 'PVOutput system id');

// In case LIFETIMNE is not define inb config.sys, default to LIFETIME mode
if (!defined('LIFETIME'))
  define('LIFETIME', 1);

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

// array holding last received values per inverter, index by inverter id
$total = array();
// When did we last send to PVOUtput?
$last = 0;
// When did we last send a reply back?
$lastkeepalive = 0;

/*
 * Compute aggregate info to send to PVOutput
 * See http://pvoutput.org/help.html#api-addstatus
 */
function submit($total, $systemid) {
  // Compute aggragated data: energy, power, avg temp avg volt
  // Power is avg power over the reporting interval
  $e = 0.0;
  $p = 0.0;
  $temp = 0.0;
  $volt = 0.0;
  foreach ($total as $t) {
    $e += $t['Energy'];
    $p += (double)$t['Power'] / $t['Count'];
    $temp += $t['Temperature'];
    $volt += $t['Volt'];
  }
  $temp /= count($total);
  $volt /= count($total);
  $p = round($p);

  if (LIFETIME)
    report(sprintf('=> PVOutput v1=%dWh v2=%dW v5=%.1fC v6=%.1fV',
      $e, $p, $temp, $volt));
  else
    report(sprintf('=> PVOutput v2=%dW v5=%.1fC v6=%.1fV', $p, $temp, $volt));
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

  // We have all the data, prepare POST to PVOutput
  $headers = "Content-type: application/x-www-form-urlencoded\r\n" .
    'X-Pvoutput-Apikey: ' . APIKEY . "\r\n" .
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
    report('POST failed, check your APIKEY and SYSTEMID');
  else {
    $reply = fread($fp, 100);
    report('PVOutput replies: ' . $reply);
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
  while (true) {
    $pos = strpos($buf, "\r");
    if ($pos === false) {
      $str = socket_read($socket, 128, PHP_NORMAL_READ);
      if ($str === false || strlen($str) == 0)
        return false;
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
  global $total, $last, $lastkeepalive, $systemid;

  while (true) {
    $str = reader($socket);
    if ($str === false) {
        return;
    }
    // Send a reply if the last reply is 200 seconds ago
    if ($lastkeepalive < time() - 200) {
      if (socket_write($socket, "0E0000000000cgAD83\r") === false)
        return;
      $lastkeepalive = time();
      //report('send keepalive');
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
      if (strlen($bin) != 42)
        continue;

      //echo bin2hex($bin) . PHP_EOL;
      $v = unpack('VIDDec/c18dummy/CState/nDCCurrent/nDCPower/' .
         'nEfficiency/cACFreq/nACVolt/cTemperature/nWh/nkWh', $bin);
      $v['DCCurrent'] *= 0.025;
      $v['Efficiency'] *= 0.001;
      $LifeWh = $v['kWh'] * 1000 + $v['Wh'];
      $ACpower = $v['DCPower'] * $v['Efficiency'];
      $DCVolt = $v['DCPower'] / $v['DCCurrent'];

      // Clear stale entries (older than 1 hour)
      foreach ($total as $key => $t) {
        if ($total[$key]['TS'] < $time - 3600)
          unset($total[$key]);
      }

      $id = $v['IDDec'];
      $time = time();
      // Record in $total indexed by id: cummulative energy
      $total[$id]['Energy'] = $LifeWh;
      // Record in $total, indexed by id: count, cummulative power,
      // volt and temp
      if (!isset($total[$id]['Power'])) {
        $total[$id]['Power'] = 0;
        $total[$id]['Count'] = 0;
      }
      $total[$id]['Count']++;
      $total[$id]['Power'] += $v['DCPower'];
      $total[$id]['Volt'] = $v['ACVolt'];
      $total[$id]['Temperature'] = $v['Temperature'];

      printf('%s DC=%3dW %5.2fV %4.2fA AC=%3dV %6.2fW E=%4.2f T=%2d ' .
        'S=%d L=%.3fkWh' .  PHP_EOL,
        $id, $v['DCPower'], $DCVolt, $v['DCCurrent'],
        $v['ACVolt'], $ACpower,
        $v['Efficiency'], $v['Temperature'], $v['State'],
        $LifeWh / 1000);

      if (defined('MYSQLDB'))
        submit_mysql($v, $LifeWh);

      if (MODE == 'SPLIT') {
        // time to report for this inverter?
        if (!isset($total[$id]['TS']) || $total[$id]['TS'] < $time - 600) {
          submit(array($total[$id]), $systemid[$id]);
          $total[$id]['Power'] = 0;
          $total[$id]['Count'] = 0;
        }
      } else {
        // in AGGREGATE mode, only report if we have seen all inverters
        if (count($total) != IDCOUNT) {
          report('Expecing IDCOUNT=' . IDCOUNT . ' IDs, seen ' .
            count($total) . ' IDs');
        } elseif ($last < $time - 600) {
          submit($total, SYSTEMID);
          $last = $time;
          foreach ($total as $key => $t) {
            $total[$key]['Power'] = 0;
            $total[$key]['Count'] = 0;
          }
        }
      }
      $total[$id]['TS'] = $time;
    }
  }
}

/*
 * Setup a listening socket
 */
function setup() {
  $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
  if ($socket === false)
    fatal('socket_create');
  socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
  $ok = socket_bind($socket, '0.0.0.0', 5040);
  if (!$ok) 
    fatal('socket_bind');
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
    socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO,
      array('sec' => 90, 'usec' => 0));
    socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
    socket_getpeername($client, $peer);
    report('Accepted connection from ' . $peer);
    process($client);
    socket_close($client);
    report('Connection closed'); 
  }
}

if (MODE == 'SPLIT' && count($systemid) != IDCOUNT) {
  report('In SPLIT mode, define IDCOUNT systemid mappings');
  exit(1);
}
  
$socket = setup();
loop($socket);
socket_close($socket);

?>        
