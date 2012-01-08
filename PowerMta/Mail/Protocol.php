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
 * @subpackage Protocol
 * @copyright  2012 mitchenall.com
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */



/**
 * ESmtp extension of Zend_Mail_Protocol_Smtp
 *
 * Implements all the standard SMTP command set from the parent class
 * as normal, plus the additional extended commands for PowerMTA.
 *
 * PowerMTA ESMTP commands: XMRG, XPRT, XDFN, XACK
 *
 * @package    PowerMTA
 * @subpackage Protocol
 * @copyright  2012 mitchenall.com
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class PowerMta_Mail_Protocol extends Zend_Mail_Protocol_Smtp
{
    /**
     * Indicates whether server acknowledges are switched on or not
     *
     * @var boolean
     */
    protected $_xack = true;


    /**
     * @param boolean $on
     * @throws Zend_Mail_Protocol_Exception
     * @return void
     */
    public function xack($on = false) {
        if ($this->_sess !== true) {
            throw new Zend_Mail_Protocol_Exception('A valid session has not been started');
        }
        $this->_xack = $on;
        $state = $on ? 'ON' : 'OFF';
        $this->_send('XACK ' . $state . '');
        $this->_expect(250, 300);
    }

    /**
     * Issues XMRG command
     *
     * @param  string $from Sender mailbox
     * @throws Zend_Mail_Protocol_Exception
     * @return void
     */
    public function xmrg($from)
    {
        if ($this->_sess !== true) {
            throw new Zend_Mail_Protocol_Exception('A valid session has not been started');
        }

        $this->_send('XMRG FROM:<' . $from . '>');
        $this->_expect(250, 300); // Timeout set for 5 minutes as per RFC 2821 4.5.3.2

        // Set mail to true, clear recipients and any existing data flags as per 4.1.1.2 of RFC 2821
        $this->_mail = true;
        $this->_rcpt = false;
        $this->_data = false;
    }


    /**
     * Issues XDFN command
     *
     * @param  string $params
     * @throws Zend_Mail_Protocol_Exception
     * @return void
     */
    public function xdfn($params)
    {
        if ($this->_mail !== true) {
            throw new Zend_Mail_Protocol_Exception('No sender reverse path has been supplied');
        }

        $this->_send('XDFN ' . $params);
        if ($this->_xack) {
            $this->_expect(array(250, 251), 300); // Timeout set for 5 minutes as per RFC 2821 4.5.3.2
        }
    }



    /**
     * Issues RCPT command
     *
     * @param  string $to Receiver(s) mailbox
     * @throws Zend_Mail_Protocol_Exception
     * @return void
     */
    public function rcpt($to)
    {
        if ($this->_mail !== true) {
            throw new Zend_Mail_Protocol_Exception('No sender reverse path has been supplied');
        }

        // Set rcpt to true, as per 4.1.1.3 of RFC 2821
        $this->_send('RCPT TO:<' . $to . '>');
        if ($this->_xack) {
            $this->_expect(array(250, 251), 300); // Timeout set for 5 minutes as per RFC 2821 4.5.3.2
        }
        $this->_rcpt = true;
    }


    /**
     * @param  array $parts
     * @throws Zend_Mail_Protocol_Exception
     * @return void
     */
    public function xprts($parts)
    {
        // Ensure recipients have been set
        if ($this->_rcpt !== true) {
            throw new Zend_Mail_Protocol_Exception('No recipient forward path has been supplied');
        }

        if (count($parts) == 0) {
            throw new Zend_Mail_Protocol_Exception('No parts have been supplied');
        }

        $partCounter = 0 ;
        $totalParts = count($parts);
        if ($totalParts == 1) {
	        $this->_send('XPRT 1 LAST');
        } else {
	        $this->_send('XPRT 1');
        }
        $this->_expect(354, 120); // Timeout set for 2 minutes as per RFC 2821 4.5.3.2

        foreach($parts as $data) {
            $partCounter++;
            if ($partCounter > 1) {
                if ($partCounter == $totalParts) {
                    $this->_send('XPRT '.$partCounter.' LAST');
                } else {
                    $this->_send('XPRT '.$partCounter);
                }
                $this->_expect(354, 120);
            }
            foreach (explode(Zend_Mime::LINEEND, $data) as $line) {
                if (strpos($line, '.') === 0) {
                    // Escape lines prefixed with a '.'
                    $line = '.' . $line;
                }
                $this->_send($line);
            }
            $this->_send('.');
        	$this->_expect(250, 600); // Timeout set for 10 minutes as per RFC 2821 4.5.3.2
		}

        $this->_data = true;
    }

}
