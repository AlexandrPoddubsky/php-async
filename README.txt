
PHP asynchronous execution framework
------------------------------------

This framework allows you to execute asynchronously arbitrary shell command from
PHP process. It features the following functionality:
* open for communication between the PHP process and the child one. Right now
  we support reading child's STDOUT and STDERR.
* ability to query whether the child process has finished its execution
* query PID of the child process, possibly for sending signals to it
* read exit code of the child process once it has finished its execution
* pre-inclined for easy caching of the child process results

------------------
Examples of usage:
------------------

Most simple asynchronous execution:
-----------------------------------
<?php
$command = 'my-command';
$args = array();
$args[] = array(
  'key' => '--name-of-the-argument',
  'glue' => ' ',
  'value' => 'value of the argument',
);

$child = new ToolsAsyncResult($cmd, $args);

// Your 'my-command --name-of-the-argument "value of the argument"' is being
// executed right now. In the mean time you can do something useful in your main
// PHP process.
do_something_useful();

// When you decide you need the results of asynchronous child process, simply do
// the following. If the command has not finished yet, your main PHP process
// will sleep until the execution is finished.
$result = $child->result();

if ($result['exit'] != 0) {
  // Whoups... The child process did not terminate with exit code 0.
  // Let's see, maybe there is more hints about what went wrong in the STDERR.
  $stderr = $result['stderr'];
}

// Now let's save the STDOUT from the child process somewhere.
$stdout = $result['stdout'];
?>

Run the asynchronous command and query whether it has finished:
---------------------------------------------------------------
<?php
$command = 'my-command';
$args = array();
$args[] = array(
  'key' => '--name-of-the-argument',
  'glue' => ' ',
  'value' => 'value of the argument',
);

$child = new ToolsAsyncResult($cmd, $args);

// While the child process is running, and as we do not want to sleep in the
// main PHP process waiting for the results, let's keep doing something useful.
while ($child->isRunning()) {
  do_something_useful();
}

// Now we fetched the child process results without actually sleeping a second
// in the main PHP process.
$result = $child->result();

?>

Example of synchronous caching:
-------------------------------
<?php

/**
 * Simply encapsulate doing something in a child process asynchronously.
 *
 * But let's put a bit on top of it. Before we take off to create a child
 * process, let's check if the results of $cmd are not available in cache. If
 * they are available, we return them right away without bothering with the
 * whole thing of asynchronous command.
 * Also, when the asynchronous command finishes execution, we take a note of its
 * result and if it's positive (exit code equals 0) we store it in the cache
 * bin. That way we guarantee asynchronous command will be only initiated for
 * commands that have not been run before.
 */
function do_something_asynchronously($cmd) {
  $cache = cache_bin_get($cmd);
  if ($cache) {
    // ToolsResult class has identical methods as the ToolsAsyncResult does,
    // but this one does not execute any asynchronous command, but simply stores
    // the $cache result until it is requested at some later point. That way we
    // can freely swap between ToolsResult and ToolsAsyncResult classes without
    // modifying the rest of our code.
    return new ToolsResult($cache);
  }
  return new ToolsAsyncResult($cmd, array(), 'my_process_callback', array($cmd));
}

/**
 * This function plays on par with do_something_asynchronously().
 *
 * When the child process has finished its execution, its results will be passed
 * into this process function before being returned to whoever have requested
 * them. We take the opportunity to store the results of asynchronous command
 * execution into our cache bin so next time they can be fetched much faster
 * from there.
function my_process_callback($info, $cmd) {
  if ($info['exit'] == 0) {
    cache_bin_set($cmd, $info);
  }
  // We could also alter what gets returned to whoever requested the results of
  // asynchronous command execution by just returning something else than $info.
  // But let's keep this example simple.
  return $info;
}

$result = do_something_asynchronously('my-command');
// This line takes to execute as long as the asynchronous command takes to
// execute.
$result->result();

// But if we repeat the same thing over again, our results are already cached
// and this time it happens instantaneously. All you have to do, is to implement
// some cache storage bin.
$result = do_something_asynchronously('my-command');
$result->result();
?>

-----------------
Issues/Questions?
-----------------

Do check the php-async.php file to see full options and features provided by the
framework.
