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
 * PowerMta_Mail_Merge
 *
 * Subclass of PowerMta_Mail, instances of this class represent a mail
 * to be used in a mail merge operation.  Instead of using the addTo()
 * method to add recipients, a new method, addMergeRecipient(), is
 * included which takes objects implementing the PowerMta_Mail_Merge_Recipient
 * interface.
 *
 * To create a mail merge message, follow exactly the same steps as for
 * a Zend_Mail message, but instead of calling addTo(), use addMergeRecipient()
 *
 * When sent via the PowerMta_Mail_Transport, the message is also parsed
 * for special [XPRT:subject1,subjectN][/XPRT] tags within the encoded MIME
 * parts of the message so that they can be split into XPRTs and recipients
 * recieve the correct parts based on the subjects returned via the
 * getMergeSubjects() method on the recipient.  For example, a chunk of the
 * message is only to be received by some recipients, it should be marked
 * out using the tags, e.g.
 *
 * [XPRT:newCustomers]Content only to be sent to new customers[/XPRT]
 *
 * In the recipient object, the getMergeSubjects() should be implemented
 * something like the following:
 *
 * function getMergeSubjects() {
 *    if ($this->isNewCustomer()) {
 *      return array('newCustomer');
 *    }
 *    return array();
 * }
 *
 * Additional merge data can also be included to be replaced by the MTA
 * when sending out the messages.  If you wanted to include the customer's
 * first name in each email, you could specify it in the email body using
 * square brackets, e.g.
 *
 * Dear [Forename]
 *
 * Then in the getMergeData() method for the recipient ensure that the
 * forname is returned as follows:
 *
 * function getMergeData() {
 *    return array('Forename'=>$this->getForename());
 * }
 *
 *   @package PowerMTA
 *    @author Mark Mitchenall <mark@mitchenall.com>
 * @copyright 2008 mitchenall.com
 *   @license http://framework.zend.com/license/new-bsd     New BSD License
 */
class PowerMta_Mail_Merge extends PowerMta_Mail
{
    /**
     * Array of all recipients
     * @var array
     */
    protected $_recipients = array();

    protected $_alternateSubject = null;

    /**
     * Specific to PowerMTA, the JobID this merge
     * will be associated with in PowerMTA
     * @var string
     */
    public $_jobId = null;

    /**
     * Specific to PowerMTA, specifies the virtual MTA
     * to use for the mailing.
     * @var string
     */
    public $_virtualMta = null;

    /**
     * @param mixed $subject
     * @return void
     */
    public function setAlternateSubject($subject)
    {
        $subject = strtr($subject,"\r\n\t–",'???-');
        $this->_alternateSubject = $this->_encodeHeader($subject);
    }

    public function setSubject($subject)
    {
        $subject = strtr($subject,"\r\n\t–",'???-');
        parent::setSubject($subject);
    }

    /**
     * @return string
     */
    public function getAlternateSubject()
    {
        return $this->getSubject();
    }

    /**
     * Sets the text body for the message.
     *
     * @param  string $txt
     * @param  string $charset
     * @param  string $encoding
     * @return PowerMta_Mail Provides fluent interface
    */
    public function setBodyTextParts($txtArray, $charset = null, $encoding = Zend_Mime::ENCODING_QUOTEDPRINTABLE)
    {
        if ($charset === null) {
            $charset = $this->_charset;
        }

        $mp = new Zend_Mime_Part($txtArray);
        $mp->encoding = $encoding;
        $mp->type = Zend_Mime::TYPE_TEXT;
        $mp->disposition = Zend_Mime::DISPOSITION_INLINE;
        $mp->charset = $charset;

        $this->_bodyText = $mp;

        return $this;
    }


    /**
     * @param mixed $mta
     * @return
     */
    public function setVirtualMta($mta)
    {
        $this->addHeader('x-virtual-mta', $mta);
        $this->_virtualMta = $mta;
    }

    /**
     * @return
     */
    public function getVirtualMta()
    {
        return $this->_virtualMta;
    }

    /**
     * @param mixed $mta
     * @return
     */
    public function setJobId($jobID)
    {
        $this->addHeader('x-job', $jobID);
        $this->_jobId = $jobID;
    }

    /**
     * @return
     */
    public function getJobId()
    {
        return $this->_jobId;
    }

    /**
     * Adds merge recipient
     *
     * @param  object recipient
     * @return PowerMta_Mail Provides fluent interface
     */
    public function addMergeRecipient(PowerMta_Mail_Merge_Recipient $recipient)
    {
        $this->_recipients[$recipient->getEmailAddress()] = $recipient;
        return $this;
    }

    /**
     * @return array
     */
    public function getRecipients()
    {
        return $this->_recipients;
    }


    /**
     * @param mixed $email
     * @return string
     */
    public function getRecipientName($email)
    {
        if (isset($this->_recipients[$email])) {
            $recipient = $this->_recipients[$email];
            $name = $recipient->getName();
            $name = str_replace("�", '', $name);
            $name = str_replace("'", '', $name);
            $name = str_replace('"', '', $name);
            $name = $this->_encodeHeader($name);
            if (strpos($name, '?iso-8859-1')) {
                return '';
            }
            return $name;
        }
        return '';
    }

    /**
     * @param mixed $email
     * @return array subjects to include for recipient
     */
    public function getRecipientMergeContentTags($email)
    {
        if (isset($this->_recipients[$email])) {
            return $this->_recipients[$email]->getMergeContentTags();
        }
        return array();
    }

    /**
     * @param mixed $email
     * @return array Associative array of merge fields
     */
    public function getRecipientMergeData($email)
    {
        if (isset($this->_recipients[$email])) {
            $data = $this->_recipients[$email]->getMergeData();
            foreach($data as $key => $value){
                $data[$key] = $this->_encodeHeader($value);
            }
            return $data;
        }
        return array();
    }

    /**
     * Sends this email using the given transport or a previously
     * set DefaultTransport or the internal mail function if no
     * default transport had been set.
     *
     * @param  PowerMta_Mail_Transport_Abstract $transport
     * @return PowerMta_Mail                    Provides fluent interface
     */
    public function send($transport = null)
    {
        if ($transport === null) {
            if (! self::$_defaultTransport instanceof PowerMta_Mail_Transport) {
                $transport = new PowerMta_Mail_Transport();
            } else {
                $transport = self::$_defaultTransport;
            }
        }

        if (is_null($this->_date)) {
            $this->setDate();
        }
//        $this->addHeader('Precedence', 'bulk');
        $transport->sendMerge($this);

        return $this;
    }

}
