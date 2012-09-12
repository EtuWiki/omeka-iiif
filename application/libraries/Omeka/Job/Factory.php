<?php
/**
 * @copyright Roy Rosenzweig Center for History and New Media, 2010
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 */

/**
 * Factory for instantiating Omeka_Job instances.
 *
 * @package Omeka
 * @copyright Roy Rosenzweig Center for History and New Media, 2010
 */
class Omeka_Job_Factory
{
    private $_options = array();

    public function __construct(array $options = array())
    {
        $this->_options = $options;
    }

    /**
     * Decode a message from JSON and use the results to instantiate a new job 
     * instance.
     *
     * @param string $json
     */
    public function from($json)
    {
        try {
            $data = Zend_Json::decode($json);
        } catch (Zend_Json_Exception $e) {
            throw new Omeka_Job_Factory_MalformedJobException(__("Zend_Json_Exception: %s (%s)", $e->getMessage(), $json));
        }
        if (!$data) {
            throw new Omeka_Job_Factory_MalformedJobException(__("The following malformed job was given: %s", $json));
        }
        if (!array_key_exists('className', $data)) {
            throw new Omeka_Job_Factory_MalformedJobException(__("No 'className' attribute was given in the message."));
        }
        if (!array_key_exists('options', $data)) {
            throw new Omeka_Job_Factory_MalformedJobException(__("No 'options' attribute was given in the message."));
        }

        return $this->build($data);
    }

    /**
     * Instantiate a new job instance from the arguments given.
     *
     * @param string $className
     * @param array $options
     */
    public function build($data)
    {
        $className = $data['className'];
        if (!class_exists($className, true)) {
            throw new Omeka_Job_Factory_MissingClassException(__("Job class named %s does not exist.", $className));
        }
        if (!isset($data['options'])) {
            $data['options'] = array();
        }
        if (isset($this->_options['db']) && isset($data['createdBy'])) {
            $user = $this->_options['db']->getTable('User')->find($data['createdBy']);
            if (!$user) {
                throw new Omeka_Job_Factory_MalformedJobException(__("The user that created this job does not exist."));
            }
            $data['options']['user'] = $user;
        }

        $jobOptions = array_merge($data['options'], $this->_options);
        return new $className($jobOptions);
    }
}
