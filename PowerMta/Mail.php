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
 * PowerMta_Mail
 *
 * This is just a simple wrapper around Zend_Mail to provide a couple of
 * additional API calls to set the virtual MTA and JobId for a message.
 *
 * @package    PowerMTA
 * @copyright  2012 mitchenall.com
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class PowerMta_Mail extends Zend_Mail
{


    /**
     * @param string $charset
     */
    public function __construct($charset = 'utf-8')
    {
        parent::__construct($charset);
    }


    /**
     * @param string
     * @return void
     */
    public function setVirtualMta($virtualMta)
    {
        $this->addHeader('x-virtual-mta',$virtualMta);
    }

    /**
     * @param string
     * @return void
     */
    public function setJobId($jobId)
    {
        $this->addHeader('x-jobid',$jobId);
    }

    /**
     * @param string $unsubAddress
     */
    public function setUnsubscribe($unsubAddress)
    {
        $headerValue = '<mailto:'.$unsubAddress.'>';
        $this->addHeader('List-Unsubscribe', $headerValue);
    }
}
