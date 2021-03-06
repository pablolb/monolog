<?php

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler;

use Monolog\Logger;

/**
 * Stores to any socket - uses fsockopen() or pfsockopen().
 * 
 * @author Pablo de Leon Belloc <pablolb@gmail.com>
 * @see    http://php.net/manual/en/function.fsockopen.php
 */
class SocketHandler extends AbstractProcessingHandler
{

    private $connectionString;
    private $connectionTimeout;
    private $resource;
    private $timeout = 0;
    private $persistent = false;
    private $errno;
    private $errstr;

    /**
     * @param string  $connectionString Socket connection string
     * @param integer $level            The minimum logging level at which this handler will be triggered
     * @param Boolean $bubble           Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct($connectionString, $level = Logger::DEBUG, $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->connectionString = $connectionString;
        $this->connectionTimeout = (float) ini_get('default_socket_timeout');
    }

    /**
     * Connect (if necessary) and write to the socket
     * 
     * @param array $record
     * 
     * @throws \UnexpectedValueException
     * @throws \RuntimeException
     */
    public function write(array $record)
    {
        $this->connectIfNotConnected();
        $this->writeToSocket((string) $record['formatted']);
    }

    /**
     * We will not close a PersistentSocket instance so it can be reused in other requests.
     */
    public function close()
    {
        if ($this->isPersistent()) {
            return;
        }
        $this->closeSocket();
    }

    /**
     * Close socket, if open
     */
    public function closeSocket()
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    /**
     * Set socket connection to nbe persistent. It only has effect before the connection is initiated.
     * 
     * @param type $boolean 
     */
    public function setPersistent($boolean)
    {
        $this->persistent = (boolean) $boolean;
    }

    /**
     * Set connection timeout.  Only has effect before we connect.
     * 
     * @param integer $seconds 
     * 
     * @see http://php.net/manual/en/function.fsockopen.php
     */
    public function setConnectionTimeout($seconds)
    {
        $this->validateTimeout($seconds);
        $this->connectionTimeout = (float) $seconds;
    }

    /**
     * Set write timeout. Only has effect before we connect.
     * 
     * @param type $seconds 
     * 
     * @see http://php.net/manual/en/function.stream-set-timeout.php
     */
    public function setTimeout($seconds)
    {
        $this->validateTimeout($seconds);
        $this->timeout = (int) $seconds;
    }

    /**
     * Get current connection string
     * 
     * @return string
     */
    public function getConnectionString()
    {
        return $this->connectionString;
    }

    /**
     * Get persistent setting
     * 
     * @return boolean
     */
    public function isPersistent()
    {
        return $this->persistent;
    }

    /**
     * Get current connection timeout setting
     * 
     * @return float
     */
    public function getConnectionTimeout()
    {
        return $this->connectionTimeout;
    }

    /**
     * Get current in-transfer timeout
     * 
     * @return float
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * Check to see if the socket is currently available.
     * 
     * UDP might appear to be connected but might fail when writing.  See http://php.net/fsockopen for details.
     * 
     * @return boolean
     */
    public function isConnected()
    {
        return is_resource($this->resource)
                && !feof($this->resource);  // on TCP - other party can close connection. 
    }
    
    /**
     * Allow mock
     */
    protected function pfsockopen()
    {
        return @pfsockopen($this->connectionString, -1, $this->errno, $this->errstr, $this->connectionTimeout);
    }

    /**
     * Allow mock
     */
    protected function fsockopen()
    {
        return @fsockopen($this->connectionString, -1, $this->errno, $this->errstr, $this->connectionTimeout);
    }

    /**
     * Allow mock
     */
    protected function stream_set_timeout()
    {
        return stream_set_timeout($this->resource, $this->timeout);
    }

    /**
     * Allow mock
     */
    protected function fwrite($data)
    {
        return @fwrite($this->resource, $data);
    }

    /**
     * Allow mock
     */
    protected function stream_get_meta_data()
    {
        return stream_get_meta_data($this->resource);
    }

    private function validateTimeout($value)
    {
        $ok = filter_var($value, FILTER_VALIDATE_INT, array('options' => array(
                'min_range' => 0,
                )));
        if ($ok === false) {
            throw new \InvalidArgumentException("Timeout must be 0 or a positive integer (got $value)");
        }
    }

    private function connectIfNotConnected()
    {
        if ($this->isConnected()) {
            return;
        }
        $this->connect();
    }

    private function connect()
    {
        $this->createSocketResource();
        $this->setSocketTimeout();
    }

    private function createSocketResource()
    {
        if ($this->isPersistent()) {
            $resource = $this->pfsockopen();
        } else {
            $resource = $this->fsockopen();
        }
        if (!$resource) {
            throw new \UnexpectedValueException("Failed connecting to $this->connectionString ($this->errno: $this->errstr)");
        }
        $this->resource = $resource;
    }

    private function setSocketTimeout()
    {
        if (!$this->stream_set_timeout()) {
            throw new \UnexpectedValueException("Failed setting timeout with stream_set_timeout()");
        }
    }

    private function writeToSocket($data)
    {
        $length = strlen($data);
        $sent = 0;
        while ($this->isConnected() && $sent < $length) {
            $chunk = $this->fwrite(substr($data, $sent));
            if ($chunk === false) {
                throw new \RuntimeException("Could not write to socket");
            }
            $sent += $chunk;
            $socketInfo = $this->stream_get_meta_data();
            if ($socketInfo['timed_out']) {
                throw new \RuntimeException("Write timed-out");
            }
        }
        if (!$this->isConnected() && $sent < $length) {
            throw new \RuntimeException("End-of-file reached, probably we got disconnected (sent $sent of $length)");
        }
    }

}
