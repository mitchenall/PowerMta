<?php

/**
 * mitchenall.com
 *
 * @package    PowerMTA
 * @copyright  2012 mitchenall.com
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

/**
 * PowerMta_Mail_Merge_Recipient
 *
 * Provides interface for getting required data from a merge recipient
 *
 * @package PowerMTA
 * @subpackage Merge
 * @author Mark Mitchenall <mark@mitchenall.com>
 * @copyright  2012 mitchenall.com
 * @access public
 */
interface PowerMta_Mail_Merge_Recipient
{
    /**
     * @return string
     */
    public function getEmailAddress();

    /**
     * @return string name of the recipient, for the To: header
     */
    public function getName();

    /**
     * @return string associative array of merge data fields/values
     */
    public function getMergeData();

    /**
     * @return array of content tag strings
     */
    public function getMergeContentTags();

    /**
     * @return boolean True if this recipient gets this part of the mail
     */
    public function getsPart(PowerMta_Mail_Transport_Xprt $xprt);

    /**
     * @return boolean True if recipient should get the alternate subject line
     */
    public function getsAlternateSubject();
}