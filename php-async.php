<?php

/**
 * @file
 * Framework for asynchronous execution of commands in PHP.
 *
 * See README.txt for more details and usage examples.
 */

/**
 * Interface of result wrapper for synchronous/asynchronous command execution.
 */
interface ToolsResultInterface {

  /**
   * Retrieve results from the wrapper.
   *
   * @return mixed
   *   Any data, whatever the mere result happens to be
   */
  public function result();

  /**
   * Query whether the result is still being calculated.
   *
   * @return bool
   *   Whether the result is still being calculated
   */
  public function isRunning();
}

/**
 * Synchronous implementation of ToolsResultInterface.
 */
class ToolsResult implements ToolsResultInterface{

  /**
   * Storage for the results.
   *
   * @var mixed
   */
  protected $result;

  /**
   * Constructor.
   *
   * @param mixed $result
   *   The result that should be returned upon request. This result will be
   *   returned synchronously and instantaneously
   */
  function __construct($result) {
    $this->result = $result;
  }

  public function result() {
    return $this->result;
  }

  public function isRunning() {
    return FALSE;
  }
}

/**
 * Asynchronous implementation of ToolsResultInterface.
 */
class ToolsAsyncResult implements ToolsResultInterface{

  /**
   * Command that executes asynchronously in this object.
   *
   * @var string
   */
  protected $cmd;

  /**
   * Array of arguments that should be passed to the asynchronous command.
   *
   * This array can be either associative (long keys are assumed then) or
   * normal, then arguments are passed as it to the command. In any case, if an
   * argument happens not to be scalar, it is encoded using
   * ToolsAsyncResult::encode() method before passing on to the asynchronous
   * command.
   *
   * @var array
   */
  protected $args;

  /**
   * Array of file descriptors opened between the main process and the command.
   *
   * Keys of this array correspond to the file descriptor number in the command
   * process. So, 0 is STDIN, 1 is STDOUT, 2 is STDERR and the rest can be
   * freely used between the 2 processes.
   *
   * @var array
   */
  protected $pipes;

  /**
   * Descriptor of the child process where command executes asynchronously.
   *
   * @var resource
   */
  protected $process;

  /**
   * Process callback function to process results of the command.
   *
   * If it is NULL, then it means no process callback is required. The process
   * callback function will receive an array as first input argument. This array
   * will contain information on the results of command execution and will have
   * the following structure:
   * - cmd: (string) Command that was executed in the child process
   *   asynchronously
   * - args: (array) Array of input arguments for the asynchronous command as
   *   they were provided to this object in the constructor
   * - process_callback: (callable) Process callback that is being invoked
   * - exit: (int) Exit code of the asynchronous command
   * - stdout: (string) STDOUT content of the asynchronous command. Depending on
   *   your logic, you may use ToolsAsyncResult::decode() method to decode the
   *   content of the stream, if you are expecting non-scalar output from the
   *   asynchronous command
   * - stderr: (string) STDERR content of the asynchronous command. Depending on
   *   your logic, you may use ToolsAsyncResult::decode() method to decode the
   *   content of the stream, if you are expecting non-scalar output from the
   *   asynchronous command
   * - streams: (array) Array of additional output streams received from
   *   asynchronous command, if you provided any additional file descriptors in
   *   the constructor as $extra_descriptors variable. The array will be keyed
   *   by file descriptor number
   *
   * The process callback should take in this input argument, interpret it per
   * its logic and return interpreted value. Return value of the process
   * callback will be used as return value of the whole command execution, i.e.
   * it is what ToolsResultInterface->result() for this object will return.
   *
   * If no process callback is given, the return value of asynchronous command,
   * i.e. the $info array is passed directly into output of
   * ToolsResultInterface->result() for this object
   *
   * @var callable
   */
  protected $process_callback;

  /**
   * Additional input arguments to the process callback, if any were supplied.
   *
   * @var array
   */
  protected $process_callback_arguments;

  /**
   * Results of the asynchronous command execution.
   *
   * They are stored in the object so we can return results multiple times after
   * termination of the asynchronous command.
   *
   * @var mixed
   */
  protected $result;

  /**
   * Status of the asynchronous command execution.
   *
   * Latest known output of proc_get_status() function.
   *
   * @var array
   */
  protected $proc_status;

  /**
   * Constructor.
   *
   * @param string $cmd
   *   Command that should be executed asynchronously in this object.
   * @param array $args
   *   Array of arguments that should be passed to the asynchronous command.
   *   Each sub array here denotes a single argument. The sub arrays should have
   *   the following structure:
   *   - key: (string) Name of the key, i.e. argument name. If it is an unnamed
   *     argument, just make this property empty string
   *   - glue: (string) Glue between the argument key and value, i.e. things
   *     like equal sign (=) or space bar ( ). If it is unnamed argument, make
   *     this property empty string
   *   - value: (string) Value of the argument
   * @param callable $process_callback
   *   Process callback function to process results of the command. See
   *   description of $this->process_callback for more details.
   * @param array $process_callback_arguments
   *   Array of additional arguments to append, when invoking the process
   *   callback
   * @param array $extra_descriptors
   *   Additional file descriptors to be passed on to the command. The key will
   *   be descriptor number, whereas the value should be specification of
   *   descriptor in the format as expected by proc_open() function. The first 3
   *   descriptors are reserved for STDIN, STDOUT and STDERR, so
   *   $extra_descriptors must start from file descriptor #3
   */
  function __construct($cmd, array $args = array(), $process_callback = NULL, array $process_callback_arguments = array(), $extra_descriptors = array()) {
    $this->cmd = $cmd;
    $this->args = $args;
    $this->process_callback = $process_callback;
    $this->process_callback_arguments = $process_callback_arguments;

    $descriptorspec = array(
      0 => array('pipe', 'r'),
      1 => array('pipe', 'w'),
      2 => array('pipe', 'w'),
    ) + $extra_descriptors;

    $cmd = escapeshellcmd($cmd);
    foreach ($args as $arg) {
      if (is_array($arg['value'])) {
        trigger_error('Argument ' . $arg['key'] . ' has array value.');
      }
      $cmd .= ' ' . $arg['key'] . $arg['glue'];
      if ($arg['value']) {
        $cmd .= escapeshellarg($arg['value']);
      }
    }

    // We explicitly run it through bash, because if $cmd is opened directly,
    // it is executed through /bin/sh, which is SH compatibility mode (and
    // thereby has reduced functions). See http://askubuntu.com/questions/422492/why-script-with-bin-bash-is-working-with-bin-sh-not
    $this->process = proc_open('bash', $descriptorspec, $this->pipes);
    fwrite($this->pipes[0], $cmd);
    fclose($this->pipes[0]);

    if (!is_resource($this->process)) {
      trigger_error('Could not initialize asynchronous call of ' . $this->cmd);
    }
  }

  function __destruct() {
    $this->closePipes();
  }

  public function result() {
    if (is_resource($this->process)) {
      $stdout = stream_get_contents($this->pipes[1]);
      $stderr = stream_get_contents($this->pipes[2]);

      $streams = array();
      foreach ($this->pipes as $k => $pipe) {
        if (!in_array($k, array(0, 1, 2))) {
          $streams[$k] = stream_get_contents($this->pipes[$k]);
        }
      }

      $this->closePipes();
      $exit_code = proc_close($this->process);
      if ($this->proc_status && !$this->proc_status['running']) {
        // Since we have previously queried the child process and saw it
        // finished, the real exit code is available there.
        $exit_code = $this->proc_status['exitcode'];
      }
      // We have just witnessed the process is finished. So update the meta
      // information on it in the process status.
      $this->proc_status['running'] = FALSE;

      $info = array(
        'cmd' => $this->cmd,
        'args' => $this->args,
        'process_callback' => $this->process_callback,
        'exit' => $exit_code,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'streams' => $streams,
      );

      if ($exit_code !== 0) {
        trigger_error('Asynchronous call of ' . $this->cmd . ' exited with non-zero code. Info: ' . print_r($info, 1));
      }
      if ($info['stderr']) {
        trigger_error('Asynchronous call of ' . $this->cmd . ' has STDERR output. Info: ' . print_r($info, 1));
      }
      $this->result = is_callable($this->process_callback) ? call_user_func_array($this->process_callback, array_merge(array($info), $this->process_callback_arguments)) : $info;
    }
    return $this->result;
  }

  public function isRunning() {
    $status = $this->getProcStatus();
    return $status['running'];
  }

  public function getPid() {
    $status = $this->getProcStatus();
    return $status['pid'];
  }

  /**
   * Encode data so it can be safely sent between processes.
   *
   * @param mixed $data
   *   Data to be encoded
   *
   * @return string
   *   Encoded $data, so that it can be sent from one process to another as an
   *   input/output argument
   */
  public static function encode($data) {
    return base64_encode(serialize($data));
  }

  /**
   * Decode data coming from a process input/output argument.
   *
   * @param string $data
   *   Encoded data received as an input/output argument of a process
   *
   * @return mixed
   *   PHP representation of this data
   */
  public static function decode($data) {
    return unserialize(base64_decode($data));
  }

  /**
   * Clean up all the file descriptors between the main and child processes.
   */
  protected function closePipes() {
    foreach ($this->pipes as $pipe) {
      if (is_resource($pipe)) {
        fclose($pipe);
      }
    }
  }

  /**
   * Retrieve asynchronous command execution status.
   *
   * @return array
   */
  protected function getProcStatus() {
    if ((!$this->proc_status || $this->proc_status['running']) && is_resource($this->process)) {
      $this->proc_status = proc_get_status($this->process);
    }
    return $this->proc_status;
  }
}
