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
 *    @package PowerMTA
 * @subpackage Transport
 *  @copyright 2012 mitchenall.com
 *   @license http://framework.zend.com/license/new-bsd     New BSD License
 */

/**
 * PowerMta_Mail_Transport
 *
 * Extends the standard Zend SMTP transport so that PowerMta_Mail_Merge
 * messages are properly handled using the extended protocol.
 *
 * If used with a non-merge message, the transport will simply use the
 * parent class.
 *
 * @package    PowerMTA
 * @subpackage Transport
 * @copyright  2012 mitchenall.com
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class PowerMta_Mail_Transport extends Zend_Mail_Transport_Smtp
{
    /**
     * Whether we are doing a merge, or a standard
     * send.
     *
     * @var boolean
     */
    protected $_mailmerge = false;


    /**
     * Sets the connection protocol instance
     *
     * @param Zend_Mail_Protocol_Abstract $client
     *
     * @return void
     */
    public function setConnection(Zend_Mail_Protocol_Abstract $connection)
    {
        $this->_connection = $connection;
    }


    /**
     * Gets the connection protocol instance
     *
     * @return Zend_Mail_Protocol|null
     */
    public function getConnection()
    {
        return $this->_connection;
    }

    /**
     * @param  Zend_Mail_Merge $mail
     * @access public
     * @return void
     * @throws Zend_Mail_Transport_Exception if mail is empty
     */
    public function sendMerge(PowerMta_Mail_Merge $mail) {
        $this->_mailmerge = true;
        $this->send($mail);
        $this->_mailmerge = false;
    }

    /**
     * Send an email via the SMTP connection protocol
     *
     * Overloaded from method in Zend_Mail_Transport_Smtp so that
     * if the mail is a Zend_Mail_Merge instance, the message is
     * sent using the PowerMTA XMRG method by using a Zend_Mail_Merge
     * object instead of a Zend_Mail object.
     *
     * @return void
     */
    public function _sendMail()
    {
        if (!$this->_mail instanceof PowerMta_Mail_Merge) {
            // not a merge message, so just send normally
            return parent::_sendMail();
        }

        // If sending multiple messages per session use existing adapter
        if (!($this->_connection instanceof PowerMta_Mail_Protocol)) {
            // Check if authentication is required and determine required class
            $connectionClass = 'PowerMta_Mail_Protocol';
            Zend_Loader::loadClass($connectionClass);
            $this->setConnection(new $connectionClass($this->_host, $this->_port, $this->_config));
            $this->_connection->connect();
            $this->_connection->helo($this->_name);
        } else {
            // Reset connection to ensure reliable transaction
            $this->_connection->rset();
        }

        // first, we need to check whether there are any XPRTs
        // to worry about in the message
        $messagePayload = $this->header . Zend_Mime::LINEEND . $this->getBody();
        $this->_xprts = $this->getXprts($messagePayload);

        // Set mail return path from sender email address.
        // in the parent implementation, this would be the MAIL
        // message, but in this ESMTP protocol, we can use XMRG
        $this->_connection->xmrg($this->_mail->getReturnPath());

        $overallMergeData = $this->getOverallMergeData();

        // Set recipient forward paths
        foreach ($this->_mail->getRecipients() as $recipientAddress => $recipient) {

            $defaultMergeData = array(
                'Name'      => $recipient->getName(),
                'Subject'   => $this->getMailSubject($recipient),
                'parts'     => $this->getPartsForRecipient($recipient)
            );

            $mergeData = array_merge($overallMergeData, $defaultMergeData, $recipient->getMergeData());

            foreach($this->getXdfnStatements($mergeData) as $lineNumber => $xdfnStatement){
                $this->_connection->xdfn($xdfnStatement);
            }

            // finally, add the recipient.
            $this->_connection->rcpt($recipient->getEmailAddress());
        }

        // Issue XPRT command to client
        $this->_connection->xprts($this->_xprts);
    }

    /**
     * Returns the body of the mail message
     *
     * @return string
     */
    public function getBody()
    {
        return $this->addLineBreaksAroundMergeTags($this->body);
    }

    /**
     * So that merge tags don't end up creating lines
     * which are too long for the encoding, we add an
     * encoded line-break before and after them.
     *
     * @param string $body
     * @return string
     */
    function addLineBreaksAroundMergeTags($body)
    {
        $candidateTagMatches = $this->findCandidateMergeTags($body);
        $tagCounter = 0;
        $lastOffset = 0;

        if (count($candidateTagMatches) == 0) {
            return $body;
        } else {
            $newBody = '';

            foreach($candidateTagMatches as $potentialMatch){

                $tag = $potentialMatch[0][0];
                $tagOffset = $potentialMatch[0][1];

                if (!$this->_isStartXprtTag($tag) && !$this->_isEndXprtTag($tag)) {
                    $newBody .= $this->_addLineBreak(substr($body, $lastOffset, $tagOffset-$lastOffset));
                    $newBody .= $this->_addLineBreak($this->_getCleanedMergeTag($tag));
                    $lastOffset = $tagOffset + strlen($tag);
                }
            }
            $newBody .= substr($body, $lastOffset);
        }
        return $newBody;
    }

    /**
     * Finds what could be merge tags in the body text
     * passed.
     *
     * @param string $body
     * @return array
     */
    private function findCandidateMergeTags($body)
    {
        $matchCandidateMergeTagsRegex = '/\[\*?(?:[a-zA-Z\r\n=]+)?\]/sx';
        $numMatches = preg_match_all($matchCandidateMergeTagsRegex,
                                     $body,
                                     $matches,
                                     PREG_SET_ORDER+PREG_OFFSET_CAPTURE);
        return $matches;
    }

    /**
     * @param mixed $recipient
     * @return string value for the *parts reserved variable
     */
    protected function getPartsForRecipient($recipient){
        $partsForThisRecipient = array();
        $partCounter = 0;
        $contiguous = true;
        $lastPartIncluded = 0;
        foreach($this->_xprts as $part) {
            $partCounter++;
            if ($recipient->getsPart($part)) {
                $partsForThisRecipient[] = $partCounter;
            }
            if ($partCounter != $lastPartIncluded+1) {
                $contiguous = false;
            }
        }
        if ($contiguous) {
            return '1-'.$partCounter;
        } else {
            return implode(',',$partsForThisRecipient);
        }
    }

    /**
     * Messages with customise sections could have an alternative subject
     * for individual recipients.  This method asks the recipient whether
     * it gets the alternative version or not and returns the appropriate
     * subject line.
     *
     * @return string
     */
    public function getMailSubject($recipient){
        if ($recipient->getsAlternateSubject()) {
            return $this->_mail->getAlternateSubject();
        } else {
            return $this->_mail->getSubject();
        }
    }

    /**
     * @return string
     */
    protected function getOverallMergeData()
    {
        $mailHeaders = $this->_mail->getHeaders();
        $mergeData = array();
        if (isset($mailHeaders['x-job'])) {
            $jobID = $mailHeaders['x-job'][0];
            $mergeData['jobid'] = $jobID;
        }

        if (isset($mailHeaders['x-virtual-mta'])) {
            $vmta = $mailHeaders['x-virtual-mta'][0];
            $mergeData['vmta'] = $vmta;
        }
        return $mergeData;
    }

    /**
     * @param mixed $mergeData
     * @return
     */
    protected function getXdfnStatements($mergeData)
    {
        $xdfnLines = array(0 => '');
        $lineCounter = 0;
        $maxLineLength = 995; // 1000 minus XDFN and a space
        foreach($mergeData as $variableName => $value){
            $encodedVariable = $this->encodeXdfnVariable($variableName, $value);
            $encodedVariable .= ' ';
            $newLineLength = strlen($encodedVariable) + strlen($xdfnLines[$lineCounter]);
            if ($newLineLength > $maxLineLength) {
                $xdfnLines[$lineCounter] = rtrim($xdfnLines[$lineCounter]);
                $lineCounter++;
            }
            $xdfnLines[$lineCounter] .= $encodedVariable;
        }
        $xdfnLines[$lineCounter] = rtrim($xdfnLines[$lineCounter]);
        return $xdfnLines;
    }

    /**
     * @param mixed $variableName
     * @return boolean
     */
    protected function isReservedVariable($variableName)
    {
        return in_array($variableName, $this->getReservedVariableNames());
    }

    /**
     * @param mixed $variableName
     * @param mixed $variableValue
     * @return string
     */
    protected function encodeXdfnVariable($variableName, $variableValue)
    {
        if ($this->isReservedVariable($variableName)) {
            $variableName = '*'.$variableName;  //reserved variables are prefixed
        }
        if ($variableName == '*parts') {
            $encoded = sprintf('%s=%s', $variableName, $variableValue);
        } else {
            $encoded = sprintf('%s="%s"', $variableName, $variableValue);
        }
        return $encoded;
    }

    /**
     * @return array
     */
    protected function getReservedVariableNames()
    {
        return array(
            'to', 'from', 'parts', 'jobid', 'envid', 'vmta', 'date'
        );
    }

    /**
     * Format and fix headers
     *
     * Some SMTP servers do not strip BCC headers. Most clients do it themselves as do we.
     *
     * @access  protected
     * @param   array $headers
     * @return  void
     * @throws  Zend_Transport_Exception
     */
    protected function _prepareHeaders($headers)
    {
        if (!$this->_mail) {
            throw new Zend_Mail_Transport_Exception('_prepareHeaders requires a registered Zend_Mail object');
        }

        unset($headers['Bcc']);

        if ($this->_mailmerge) {
            // as this is a mailmerge operation, we need to modify the
            // standard 'To' and 'Date' headers to make sure they get
            // replaced properly during the merge.
            $headers['To'] = array('[Name] <[*to]>');
            $headers['Date'] = array('[*date]');
            $headers['Subject'] = array('[Subject]');

            if (isset($headers['List-Unsubscribe'])) {
                $headers['List-Unsubscribe'] = str_replace('##toAddress##', '[*to]', $headers['List-Unsubscribe']);
            }

            // unset the PowerMTA specific headers from the merge
            // as they're included in the XDFN messages
            unset($headers['x-virtual-mta']);
            unset($headers['x-job']);
        }

        // Prepare headers
        parent::_prepareHeaders($headers);
    }


    /**
     * @param mixed $messagePayload
     * @return
     */
    public function getXprts($messagePayload)
    {
        $xprts = array();

        $candidateTagMatches = $this->_getXprtCandidateTagOffsets($messagePayload);
        $actualTagMatches = array();
        $partCounter = 0;

        if (count($candidateTagMatches) > 0) {

            $lookingForStartTag = true;

            foreach($candidateTagMatches as $potentialMatch){

                $tag = $potentialMatch[0][0];
                $tagOffset = $potentialMatch[0][1];

                if ($this->_isStartXprtTag($tag)) {
                    if (!$lookingForStartTag) {
                        throw new Exception('Invalid XPRT Tags.  Found start when I wanted an end');
                    }
                    $lookingForStartTag = false;
                    $actualTagMatches[$partCounter]['startTag'] = $tag;
                    $actualTagMatches[$partCounter]['startTagOffset'] = $tagOffset;

                } elseif ($this->_isEndXprtTag($tag)) {
                    if ($lookingForStartTag) {
                        throw new Exception('Invalid XPRT Tags.  Found end when I wanted a start');
                    }
                    $lookingForStartTag = true;
                    $actualTagMatches[$partCounter]['endTag'] = $tag;
                    $actualTagMatches[$partCounter]['endTagOffset'] = $tagOffset;

                    $partCounter++;
                }
            }

            if (!$lookingForStartTag) {
                throw new Exception('Invalid XPRT Tags.  Start found without an end');
            }
        }

        if ($partCounter == 0) {
            $xprts[] = new PowerMta_Mail_Transport_Xprt($messagePayload, array());
        } else {

            $data = substr($messagePayload, 0, $actualTagMatches[0]['startTagOffset']);
            $data = $this->_addLineBreak($data);
            $xprts[] = new PowerMta_Mail_Transport_Xprt($data);
            $lastOffset = $actualTagMatches[0]['startTagOffset'];

            foreach($actualTagMatches as $xprtMatch) {

                if ($lastOffset < $xprtMatch['startTagOffset']) {
                    // there's some stuff which isn't tagged, so
                    // we need to add a tagless xprt
                    $nonTaggedSectionStartOffset = $lastOffset;
                    $lengthOfNonTaggedSection = $xprtMatch['startTagOffset'] - $nonTaggedSectionStartOffset;
                    $nonTaggedSection = substr($messagePayload, $nonTaggedSectionStartOffset, $lengthOfNonTaggedSection);
                    $nonTaggedSection = $this->_addLineBreak($nonTaggedSection);
                    $xprts[] = new PowerMta_Mail_Transport_Xprt($nonTaggedSection);
                }

                $subjects = $this->_getXprtTags($xprtMatch['startTag']);
                $dataStartOffset = $xprtMatch['startTagOffset'] + strlen($xprtMatch['startTag']);
                $dataLength = $xprtMatch['endTagOffset'] - $dataStartOffset ;
                $data = substr($messagePayload, $dataStartOffset,$dataLength);
                $data = $this->_addLineBreak($data);
                $xprts[] = new PowerMta_Mail_Transport_Xprt($data, $subjects);
                $lastOffset = $xprtMatch['endTagOffset'] + strlen($xprtMatch['endTag']);
            }
            if (strlen($messagePayload) > $lastOffset) {
                // add the last part as-is
                $xprts[] = new PowerMta_Mail_Transport_Xprt(substr($messagePayload, $lastOffset));
            }
        }

        return $xprts;
    }

    /**
     * @param string $data
     * @return string
     */
    private function _addLineBreak($data){
        $data = rtrim($data, Zend_Mime::LINEEND);
        $data = rtrim($data, '=');
        $data .= '='.Zend_Mime::LINEEND;
        return $data;
    }

    /**
     * @return array
     */
    function _getXprtCandidateTagOffsets($payload)
    {
        $matchCandidateXprtTagsRegex = '/\[(?:[\/XPRT\r\n=]{4,8})(?::(?:[-a-zA-Z0-9_,\r\n=]+))?\]/sx';
        $numMatches = preg_match_all($matchCandidateXprtTagsRegex,
                                     $payload,
                                     $matches,
                                     PREG_SET_ORDER+PREG_OFFSET_CAPTURE);
        return $matches;
    }

    /**
     * @param mixed $tag
     * @return string
     */
    private function _getCleanedMergeTag($tag)
    {
        $cleanTag = str_replace("\n", '', $tag);
        $cleanTag = str_replace("\r", '', $cleanTag);
        $cleanTag = str_replace("=", '', $cleanTag);
        return $cleanTag;
    }

    /**
     * @param mixed $tag
     * @return
     */
    private function _isStartXprtTag($tag)
    {
        $tag = $this->_getCleanedMergeTag($tag);
        return preg_match('/^\[XPRT(:[-a-zA-Z0-9_,]*)?\]$/',$tag,$matches);
    }

    /**
     * @param mixed $tag
     * @return
     */
    private function _isEndXprtTag($tag)
    {
        $tag = $this->_getCleanedMergeTag($tag);
        return $tag == '[/XPRT]';
    }

    /**
     * @param mixed $tag
     * @return array
     */
    private function _getXprtTags($tag)
    {
        $tag = $this->_getCleanedMergeTag($tag);
        if (!preg_match('/^\[XPRT(:([-a-zA-Z0-9_,]*))?\]$/',$tag,$matches)) {
            return array();
        }
        return explode(',',strtolower($matches[2]));
    }
}


/**
 *
 *
 */
class PowerMta_Mail_Transport_Xprt{

    protected $_content;
    protected $_tags;

    /**
     * Constructor
     * @access protected
     */
    function PowerMta_Mail_Transport_Xprt($content, $tags = array())
    {
        $this->_content = $content ;
        $this->_tags = $tags;
    }

    /**
     * @return array
     */
    function getMergeContentTags()
    {
        return $this->_tags;
    }

    /**
     * @param mixed $subject
     * @return boolean
     */
    function hasSubject($tag)
    {
        return in_array($tag, $this->_tags);
    }

    /**
     * @return string
     */
    function getData()
    {
        return $this->_content;
    }

    /**
     * @return string
     */
    function __toString()
    {
        return $this->getData();
    }
}