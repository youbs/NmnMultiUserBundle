<?php

namespace Nmn\MultiUserBundle\Manager;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use FOS\UserBundle\Model\UserInterface;
use Nmn\MultiUserBundle\Event\ManualLoginEvent;

/**
 * Description of UserDiscriminator
 *
 * @author leonardo proietti (leonardo@netmeans.net)
 * @author eux (eugenio@netmeans.net)
 */
class UserDiscriminator
{
    const SESSION_NAME = 'nmn_user.user_discriminator.class';

    protected $serviceContainer;

    protected $entities;

    protected $registrationFormTypes;

    protected $profileFormTypes;

    protected $userFactories;

    protected $registrationForm = null;

    protected $profileForm = null;

    protected $class = null;

    protected $registrationFormOptions = array();

    protected $profileFormOptions = array();

    /**
     *
     * @param ContainerInterface $serviceContainer
     */
    public function __construct(ContainerInterface $serviceContainer, array $parameters)
    {
        $this->serviceContainer = $serviceContainer;

        $config = $this->buildConfig($parameters);
    }


    /**
     *
     * @param InteractiveLoginEvent $event
     */
    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event)
    {
        $user = $event->getAuthenticationToken()->getUser();
        $this->setClass(get_class($user), true);
    }

    /**
     *
     * @param ManualLoginEvent $event
     */
    public function onSecurityManualLogin(ManualLoginEvent $event)
    {
        $user = $event->getUser();
        $this->setClass(get_class($user), true);
    }

    /**
     *
     * @return array
     */
    public function getClasses()
    {
        return $this->entities;
    }


    /**
     *
     * @param string $class
     */
    public function setClass($class, $persist = false)
    {
        if (!in_array($class, $this->entities)) {
            throw new \LogicException(sprintf('Impossible to set the class discriminator, because the class "%s" is not present in the entities list', $class));
        }

        if ($persist) {
            $session = $this->serviceContainer->get('session');
            $session->set(static::SESSION_NAME, $class);
        }

        $this->class = $class;
    }

    /**
     *
     * @return string
     */
    public function getClass()
    {
        if (!is_null($this->class)) {
            return $this->class;
        }

        $session     = $this->serviceContainer->get('session');
        $storedClass = $session->get(static::SESSION_NAME, null);

        if ($storedClass) {
            $this->class = $storedClass;
        }

        if (is_null($this->class)) {
            $this->class = $this->entities[0];
        }

        return $this->class;
    }

    /**
     *
     * @return type
     */
    public function createUser()
    {
        $class   = $this->getClass();
        $factory = $this->userFactories[$class];
        $user    = $factory::build($class);

        return $user;
    }


    /**
     *
     * @return \Symfony\Component\Form\Form
     */
    public function getRegistrationForm()
    {
        if (is_null($this->registrationForm)) {
            $formFactory            = $this->serviceContainer->get('form.factory');
            $type                   = $this->getRegistrationFormType($this->getClass());
            $this->registrationForm = $formFactory->createNamed($type->getName(), $type, null, $this->registrationFormOptions[$this->getClass()]);
        }

        return $this->registrationForm;
    }

    /**
     *
     * @return \Symfony\Component\Form\Form
     */
    public function getProfileForm()
    {
        if (is_null($this->profileForm)) {
            $formFactory        = $this->serviceContainer->get('form.factory');
            $type               = $this->getProfileFormType($this->getClass());
            $this->profileForm  = $formFactory->createNamed($type->getName(), $type, null, $this->profileFormOptions[$this->getClass()]);
        }

        return $this->profileForm;
    }

    /**
     *
     * @return \Symfony\Component\Form\FormTypeInterface
     * @throws \LogicException
     */
    protected function getRegistrationFormType($class)
    {
        $className = $this->registrationFormTypes[$class];
        $type      = new $className($class);

        return $type;
    }

    /**
     *
     * @return \Symfony\Component\Form\FormTypeInterface
     * @throws \LogicException
     */
    protected function getProfileFormType($class)
    {
        $className = $this->profileFormTypes[$class];
        $type      = new $className($class);

        return $type;
    }

    /**
     * This function is needed due a bad bundle architecture.
     * I would have had to use a MultiUser configuration with default values
     *
     * @param array $parameter
     */
    protected function setRegistrationFormOptions(array $parameter)
    {
        if (!array_key_exists('registration_options', $parameter) || !array_key_exists('validation_groups', $parameter['registration_options'])) {
            $this->registrationFormOptions[$parameter['entity']] = array('validation_groups' => array('Registration', 'Default'));
            return;
        }

        $this->registrationFormOptions[$parameter['entity']] = $parameter['registration_options'];
    }

    /**
     * This function is needed due a bad bundle architecture.
     * I would have had to use a MultiUser configuration with default values
     *
     * @param array $parameter
     */
    protected function setProfileFormOptions(array $parameter)
    {
        if (!array_key_exists('profile_options', $parameter) || !array_key_exists('validation_groups', $parameter['profile_options'])) {
            $this->profileFormOptions[$parameter['entity']] = array('validation_groups' => array('Profile', 'Default'));
            return;
        }

        $this->profileFormOptions[$parameter['entity']] = $parameter['profile_options'];
    }

    /**
     *
     * @param array $entities
     * @param array $registrationForms
     * @param array $profileForms
     */
    protected function buildConfig(array $parameters)
    {
        $entities               = array();
        $registrationFormTypes  = array();
        $profileFormTypes       = array();
        $userFactoriesTypes     = array();

        foreach ($parameters['classes'] as $parameter) {

            array_walk($parameter, function($val, $key) use(&$parameter){

                if ($key == 'factory' && empty($val)) {
                        $parameter[$key] = 'Nmn\MultiUserBundle\Manager\UserFactory';
                }

                if (is_string($val) && !empty($val)) {
                    if (!class_exists($val)) {
                        throw new \LogicException(sprintf('Impossible build discriminator configuration: "%s" not found', $val));
                    }
                }
            });

            $entities[]                                  = $parameter['entity'];
            $registrationFormTypes[$parameter['entity']] = $parameter['registration'];
            $profileFormTypes[$parameter['entity']]      = $parameter['profile'];
            $userFactoriesTypes[$parameter['entity']]    = $parameter['factory'];

            $this->setRegistrationFormOptions($parameter);
            $this->setProfileFormOptions($parameter);
        }

        $this->entities              = $entities;
        $this->registrationFormTypes = $registrationFormTypes;
        $this->profileFormTypes      = $profileFormTypes;
        $this->userFactories         = $userFactoriesTypes;
    }
}

?>
