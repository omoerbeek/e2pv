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
if (!defined('VERBOSE'))
  define('VERBOSE', 0);

/*
 * Report a message
 */
function report($msg, $err = false) {
  if (!$err && !VERBOSE)
    return;
  echo date('Ymd-H:i:s') . ' ' . $msg . PHP_EOL;
}

/*
 * Fatal error, likely a configuration issue
 */
function fatal($msg) {
  report($msg . ': ' . socket_strerror(socket_last_error()), true);
  exit(1);
}

// $otal is an array holding last received values per inverter, indexed by
// inverter id. Each value is an array of name => value mappings, where name is:
// TS, Energy, Power array, Temp, Volt, State
$total = array();
// When did we last send to PVOUtput?
$last = 0;

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
      $systemid, true);
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
 * Submit data to MySQL
 */
$link = false;
function submit_mysql($v, $LifeWh) {
  global $link;

  // mysqli.reconnect is false by default
  if (is_resource($link) && !mysqli_ping($link)) {
    mysqli_close($link);
    $link = false;
  }
  if (!$link) {
    $link = mysqli_connect(MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDB,
      MYSQLPORT);
  }
  if (!$link) {
    report('Cannot connect to MySQL ' . mysqli_connect_error(), true);
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
   report('MySQL insert failed: ' . mysqli_error($link), true);
   mysqli_close($link);
   $link = false;
  }
}

/*
 * Loop processing lines from the gatway
 */
function process(Connection $conn) {
  global $total, $last, $systemid, $apikey, $ignored;

  while (true) {
    $str = $conn->getline();
    if ($str === false) {
        return;
    }
    // Send a reply if the last reply is 200 seconds ago
    if ($conn->lastkeepalive < time() - 200) {
      //echo 'write' . PHP_EOL;
      if (socket_write($conn->socket, "0E0000000000cgAD83\r") === false)
        return;
      //echo 'write done' . PHP_EOL;
      $conn->lastkeepalive = time();
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
      
      $oldcount = count($total);
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

      if (VERBOSE)
        printf('%s DC=%3dW %5.2fV %4.2fA AC=%3dV %6.2fW E=%4.2f T=%2d ' .
          'S=%d L=%.3fkWh' .  PHP_EOL,
          $id, $v['DCPower'], $DCVolt, $v['DCCurrent'],
          $v['ACVolt'], $ACPower,
          $v['Efficiency'], $v['Temperature'], $v['State'],
          $LifeWh / 1000);

      if (defined('MYSQLDB'))
        submit_mysql($v, $LifeWh);

      $min = idate('i') % 10;
      if (MODE == 'SPLIT') {
        // time to report for this inverter?
        if (!isset($total[$id]['TS']) ||
          ($total[$id]['TS'] < $time - 300 && $min < 5)) {
          $key = isset($apikey[$id]) ? $apikey[$id] : APIKEY;
          submit(array($total[$id]), $systemid[$id], $key);
          $total[$id]['TS'] = $time;
        }
      } 

      if ($oldcount == IDCOUNT - 1 && count($total) == IDCOUNT)
        report('Seen all expected ' . IDCOUNT . ' inverter IDs');

      // for AGGREGATE, only report if we have seen all inverters
      if (count($total) != IDCOUNT) {
        report('Expecting IDCOUNT=' . IDCOUNT . ' inverter IDs, seen ' .
          count($total) . ' IDs');
      } elseif ($last < $time - 300 && $min < 5) {
        submit($total, SYSTEMID, APIKEY);
        $last = $time;
      }
      if (MODE == 'AGGREGATE')
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
  // SO_REUSEADDR to make fast restarting of script possible
  socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
  $ok = socket_bind($socket, '0.0.0.0', 5040);
  if (!$ok) 
    fatal('socket_bind');
  $ok = socket_listen($socket);
  if (!$ok)
    fatal('socket_listen');
  return $socket;
}

/*
 * Loop accepting connections from the gateway
 */
function loop($socket) {
  // array used for socket_select, index by string repr of resource
  $selarray = array('accept' => $socket);
  // array of Connection instances, index by same
  $connections = array();
  $lastclean = time();

  while (true) {
    $a = $selarray;
    $no = null;
    $err = socket_select($a, $no, $no, 30, 0);
    if ($err === false) {
      fatal('socket_select');
    }
    while (count($a) > 0) {
      // process sockets with work pending
      $s = array_shift($a);
      // Accepting socket?
      if ($s == $socket) {
        $client = socket_accept($socket);
        if ($client === false) {
          continue;
        }
        $conn = new Connection($client);
        $selarray[(string)$client] = $client;
        $connections[(string)$client] = $conn;
        report('Accepted connection #' . count($connections) .  ' from ' .
          $conn->toString());
      } else {
        // Regular connection socket
        $conn = $connections[(string)$s];
        if (!$conn->reader()) {
          $conn->close();
          unset($selarray[(string)$s]);
          unset($connections[(string)$s]);
        } else {
          process($conn);
        }
      }
    }
    // Cleanup stale connections
    $time = time();
    if ($lastclean < $time - 30) {
      $lastclean = time();
      foreach ($connections as $key =>$conn) {
        if (!$conn->alive($time)) {
          report('A connection went dead...');
          $conn->close();
          unset($selarray[$key]);
          unset($connections[$key]);
        }
      }
    }
  }
}

if (isset($_SERVER['REQUEST_METHOD'])) {
  report('only command line', true);
  exit(1);
}

if (!defined('LIFETIME') || (LIFETIME !== 0 && LIFETIME !== 1)) {
  report('LIFETIME should be defined to 0 or 1', true);
  exit(1);
}
if (!defined('EXTENDED') || (EXTENDED !== 0 && EXTENDED !== 1)) {
  report('EXTENDED should be defined to 0 or 1', true);
}
if (!defined('MODE') || (MODE != 'SPLIT' && MODE != 'AGGREGATE')) {
  report('MODE should be \'SPLIT\' or \'AGGREGATE\'', true);
  exit(1);
}
if (!defined('AC') || (AC !== 0 && AC !== 1)) {
  report('AC should be defined to 0 or 1', true);
  exit(1);
}
if (MODE == 'SPLIT' && count($systemid) != IDCOUNT) {
  report('In SPLIT mode, define IDCOUNT systemid mappings', true);
  exit(1);
}
  
$socket = setup();
loop($socket);
socket_close($socket);

/*
 * class for connection maintenance
 */
class Connection {
  public $socket;
  public $buf = '';
  public $lastkeepalive = 0;
  public $last_read;

  public function __construct($socket) {
    $this->socket = $socket;
    $this->last_read = time();
  }
 
  public function reader() { 
    $ret = socket_recv($this->socket, $str, 128, 0);
    if ($ret == false || $ret == 0) {
      return false;
    }
    $this->last_read = time();
    $this->buf .= $str;
    return true;
 }

  public function getline() {
    $pos = strpos($this->buf, "\r");
    if ($pos === false) {
      return false;
    }
    $str = substr($this->buf, 0, $pos + 1);
    $this->buf = substr($this->buf, $pos + 2);
    return $str;
  }

  public function close() {
    report('Closed connection from ' . $this->toString());
    socket_close($this->socket);
    $this->socket = null;
  }

  public function toString() {
    socket_getpeername($this->socket, $peer, $port);
    return $peer . ':' . $port;
  }

  public function alive($time) {
    return $this->last_read > $time - 90;
  }
}
?>
