<?php

/**
 * mitchenall.com
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @package    PowerMTA
 * @copyright  2012 mitchenall.com
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

/**
 * PowerMta_Merge
 *
 * Represents a merge session with the MTA, e.g.
 *
 * $mail = new PowerMta_Mail_Merge();
 * $mail->setFrom('...');
 * $mail->addMergeRecipient($recipient1);
 * $mail->addMergeRecipient($recipient2);
 *
 * $merge = new PowerMta_Merge('relay.host.com','localhost');
 * $mail->send($merge->getTransport());
 * $merge->disconnect();
 *
 *   @package PowerMTA
 *    @author Mark Mitchenall <mark@mitchenall.com>
 * @copyright 2008 mitchenall.com
 *    @access public
 */
class PowerMta_Merge {

    private $_serverHost = 'localhost';

    /**
     * @var PowerMta_Mail_Transport
     */
    private $_transport = null;

    /**
     * @var PowerMta_Mail_Protocol
     */
    private $_protocol = null;

    /**
     * @param string $serverHost SM
     * @param string $originatingHost
     * @return void
     * @throws Zend_Mail_Exception
     */
    public function __construct($serverHost, $originatingHost = 'localhost')
    {
        $this->_serverHost = $serverHost;

        $this->_transport = new PowerMta_Mail_Transport();
        $this->_protocol = new PowerMta_Mail_Protocol($this->_serverHost);
        $this->_protocol->connect();
        $this->_protocol->helo($originatingHost);
        $this->_transport->setConnection($this->_protocol);
        $this->_protocol->rset();

        // switches server acknowledgements off, so names and
        // addresses had better be in a good format for the server
        $this->_protocol->xack(false);
    }

    /**
     * @return object PowerMta_Mail_Transport object
     */
    public function getTransport()
    {
        return $this->_transport;
    }

    /**
     * @return void
     */
    public function disconnect()
    {
        $this->_protocol->quit();
        $this->_protocol->disconnect();
    }
}