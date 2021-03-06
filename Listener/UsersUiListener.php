<?php

/*
 * This file is part of the Zikula package.
 *
 * Copyright Zikula Foundation - http://zikula.org/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zikula\LegalModule\Listener;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Twig_Environment;
use Zikula\Common\Translator\TranslatorInterface;
use Zikula\Core\Event\GenericEvent;
use Zikula\LegalModule\Constant as LegalConstant;
use Zikula\LegalModule\Form\Type\PolicyType;
use Zikula\LegalModule\Helper\AcceptPoliciesHelper;
use Zikula\PermissionsModule\Api\ApiInterface\PermissionApiInterface;
use Zikula\UsersModule\AccessEvents;
use Zikula\UsersModule\Api\ApiInterface\CurrentUserApiInterface;
use Zikula\UsersModule\Constant as UsersConstant;
use Zikula\UsersModule\Entity\UserEntity;
use Zikula\UsersModule\Event\UserFormAwareEvent;
use Zikula\UsersModule\Event\UserFormDataEvent;
use Zikula\UsersModule\UserEvents;

/**
 * Handles hook-like event notifications from log-in and registration for the acceptance of policies.
 */
class UsersUiListener implements EventSubscriberInterface
{
    /**
     * @var string
     */
    const EVENT_KEY = 'module.legal.users_ui_handler';

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var Twig_Environment
     */
    private $twig;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var CurrentUserApiInterface
     */
    private $currentUserApi;

    /**
     * @var AcceptPoliciesHelper
     */
    private $acceptPoliciesHelper;

    /**
     * @var array
     */
    private $moduleVars;

    /**
     * @var FormFactory
     */
    private $formFactory;

    /**
     * @var RegistryInterface
     */
    protected $doctrine;

    /**
     * @var PermissionApiInterface
     */
    protected $permissionApi;

    /**
     * Constructor.
     *
     * @param RequestStack $requestStack
     * @param Twig_Environment $twig
     * @param TranslatorInterface $translator
     * @param RouterInterface $router
     * @param CurrentUserApiInterface $currentUserApi
     * @param AcceptPoliciesHelper $acceptPoliciesHelper
     * @param array $moduleVars
     * @param FormFactoryInterface $formFactory
     * @param RegistryInterface $registry
     * @param PermissionApiInterface $permissionApi
     */
    public function __construct(
        RequestStack $requestStack,
        Twig_Environment $twig,
        TranslatorInterface $translator,
        RouterInterface $router,
        CurrentUserApiInterface $currentUserApi,
        AcceptPoliciesHelper $acceptPoliciesHelper,
        $moduleVars,
        FormFactoryInterface $formFactory,
        RegistryInterface $registry,
        PermissionApiInterface $permissionApi
    ) {
        $this->requestStack = $requestStack;
        $this->twig = $twig;
        $this->translator = $translator;
        $this->router = $router;
        $this->currentUserApi = $currentUserApi;
        $this->acceptPoliciesHelper = $acceptPoliciesHelper;
        $this->moduleVars = $moduleVars;
        $this->formFactory = $formFactory;
        $this->doctrine = $registry;
        $this->permissionApi = $permissionApi;
    }

    /**
     * Establish the handlers for various events.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            UserEvents::DISPLAY_VIEW => ['uiView'],
            AccessEvents::LOGIN_VETO => ['acceptPolicies'],
            UserEvents::EDIT_FORM => ['amendForm', -256],
            UserEvents::EDIT_FORM_HANDLE => ['editFormHandler'],
        ];
    }

    /**
     * Responds to ui.view hook-like event notifications.
     *
     * @param GenericEvent $event The event that triggered this function call
     *
     * @return void
     */
    public function uiView(GenericEvent $event)
    {
        $activePolicies = $this->acceptPoliciesHelper->getActivePolicies();
        $activePolicyCount = array_sum($activePolicies);
        $user = $event->getSubject();
        if (!isset($user) || empty($user) || $activePolicyCount < 1) {
            return;
        }

        $acceptedPolicies = $this->acceptPoliciesHelper->getAcceptedPolicies($user['uid']);
        $viewablePolicies = $this->acceptPoliciesHelper->getViewablePolicies($user['uid']);
        if (array_sum($viewablePolicies) < 1) {
            return;
        }

        $templateParameters = [
            'activePolicies' => $activePolicies,
            'viewablePolicies' => $viewablePolicies,
            'acceptedPolicies' => $acceptedPolicies,
        ];

        $event->data[self::EVENT_KEY] = $this->twig->render('@ZikulaLegalModule/UsersUI/view.html.twig', $templateParameters);
    }

    /**
     * Vetos (denies) a login attempt, and forces the user to accept policies.
     *
     * This handler is triggered by the 'user.login.veto' event.  It vetos (denies) a
     * login attempt if the users's Legal record is flagged to force the user to accept
     * one or more legal agreements.
     *
     * @param GenericEvent $event The event that triggered this handler
     *
     * @return void
     */
    public function acceptPolicies(GenericEvent $event)
    {
        $termsOfUseActive = isset($this->moduleVars[LegalConstant::MODVAR_TERMS_ACTIVE]) ? $this->moduleVars[LegalConstant::MODVAR_TERMS_ACTIVE] : false;
        $privacyPolicyActive = isset($this->moduleVars[LegalConstant::MODVAR_PRIVACY_ACTIVE]) ? $this->moduleVars[LegalConstant::MODVAR_PRIVACY_ACTIVE] : false;
        $agePolicyActive = isset($this->moduleVars[LegalConstant::MODVAR_MINIMUM_AGE]) ? $this->moduleVars[LegalConstant::MODVAR_MINIMUM_AGE] != 0 : 0;
        $cancellationRightPolicyActive = isset($this->moduleVars[LegalConstant::MODVAR_CANCELLATIONRIGHTPOLICY_ACTIVE]) ? $this->moduleVars[LegalConstant::MODVAR_CANCELLATIONRIGHTPOLICY_ACTIVE] : false;
        $tradeConditionsActive = isset($this->moduleVars[LegalConstant::MODVAR_TRADECONDITIONS_ACTIVE]) ? $this->moduleVars[LegalConstant::MODVAR_TRADECONDITIONS_ACTIVE] : false;

        if (!$termsOfUseActive && !$privacyPolicyActive && !$agePolicyActive && !$tradeConditionsActive && !$cancellationRightPolicyActive) {
            return;
        }

        /** @var UserEntity $userObj */
        $userObj = $event->getSubject();
        if (!isset($userObj) || $userObj->getUid() <= UsersConstant::USER_ID_ADMIN) {
            return;
        }

        $attributeIsEmpty = function ($name) use ($userObj) {
            if ($userObj->hasAttribute($name)) {
                $v = $userObj->getAttributeValue($name);

                return empty($v);
            }

            return true;
        };
        $termsOfUseAccepted = $termsOfUseActive ? !$attributeIsEmpty(LegalConstant::ATTRIBUTE_TERMSOFUSE_ACCEPTED) : true;
        $privacyPolicyAccepted = $privacyPolicyActive ? !$attributeIsEmpty(LegalConstant::ATTRIBUTE_PRIVACYPOLICY_ACCEPTED) : true;
        $agePolicyAccepted = $agePolicyActive ? !$attributeIsEmpty(LegalConstant::ATTRIBUTE_AGEPOLICY_CONFIRMED) : true;
        $tradeConditionsAccepted = true; //$tradeConditionsActive ? !$attributeIsEmpty(LegalConstant::ATTRIBUTE_TRADECONDITIONS_ACCEPTED) : true;
        $cancellationRightPolicyAccepted = true; //$cancellationRightPolicyActive ? !$attributeIsEmpty(LegalConstant::ATTRIBUTE_CANCELLATIONRIGHTPOLICY_ACCEPTED) : true;

        if ($termsOfUseAccepted && $privacyPolicyAccepted && $agePolicyAccepted && $tradeConditionsAccepted && $cancellationRightPolicyAccepted) {
            return;
        }

        $event->stopPropagation();
        $event->setArgument('returnUrl', $this->router->generate('zikulalegalmodule_user_acceptpolicies'));
        $session = $this->requestStack->getMasterRequest()->getSession();
        $session->set(LegalConstant::FORCE_POLICY_ACCEPTANCE_SESSION_UID_KEY, $userObj->getUid());
        $session->getFlashBag()->add('error', $this->translator->__('Your log-in request was not completed. You must review and confirm your acceptance of one or more site policies prior to logging in.'));
    }

    /**
     * @param UserFormAwareEvent $event
     */
    public function amendForm(UserFormAwareEvent $event)
    {
        $activePolicies = $this->acceptPoliciesHelper->getActivePolicies();
        if (array_sum($activePolicies) < 1) {
            return;
        }
        $user = $event->getFormData();
        $uid = !empty($user['uid']) ? $user['uid'] : null;
        $uname = !empty($user['uname']) ? $user['uname'] : null;
        $policyForm = $this->formFactory->create(PolicyType::class, [], [
            'error_bubbling' => true,
            'auto_initialize' => false,
            'mapped' => false,
            'translator' => $this->translator,
            'userEditAccess' => $this->permissionApi->hasPermission('ZikulaUsersModule::', $uname . "::" . $uid, ACCESS_EDIT)
        ]);
        $acceptedPolicies = $this->acceptPoliciesHelper->getAcceptedPolicies($uid);
        $event
            ->formAdd($policyForm)
            ->addTemplate('@ZikulaLegalModule/UsersUI/editRegistration.html.twig', [
                'activePolicies' => $this->acceptPoliciesHelper->getActivePolicies(),
                'acceptedPolicies' => $acceptedPolicies,
            ])
        ;
    }

    /**
     * @param UserFormDataEvent $event
     */
    public function editFormHandler(UserFormDataEvent $event)
    {
        $userEntity = $event->getUserEntity();
        $formData = $event->getFormData(LegalConstant::FORM_BLOCK_PREFIX);
        if (isset($formData)) {
            $policiesToCheck = [
                'termsOfUse' => LegalConstant::ATTRIBUTE_TERMSOFUSE_ACCEPTED,
                'privacyPolicy' => LegalConstant::ATTRIBUTE_PRIVACYPOLICY_ACCEPTED,
                'agePolicy' => LegalConstant::ATTRIBUTE_AGEPOLICY_CONFIRMED,
                'tradeConditions' => LegalConstant::ATTRIBUTE_TRADECONDITIONS_ACCEPTED,
                'cancellationRightPolicy' => LegalConstant::ATTRIBUTE_CANCELLATIONRIGHTPOLICY_ACCEPTED,
            ];
            $nowUTC = new \DateTime('now', new \DateTimeZone('UTC'));
            $nowUTCStr = $nowUTC->format(\DateTime::ISO8601);
            $activePolicies = $this->acceptPoliciesHelper->getActivePolicies();
            foreach ($policiesToCheck as $policyName => $acceptedVar) {
                if ($formData['acceptedpolicies_policies'] && $activePolicies[$policyName]) {
                    $userEntity->setAttribute($acceptedVar, $nowUTCStr);
                } else {
                    $userEntity->delAttribute($acceptedVar);
                }
            }
            $this->doctrine->getManager()->flush();
        }
    }
}
