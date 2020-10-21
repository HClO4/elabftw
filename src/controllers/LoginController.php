<?php
/**
 * @author Nicolas CARPi <nico-git@deltablot.email>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Controllers;

use Elabftw\Elabftw\App;
use Elabftw\Elabftw\Auth;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Exceptions\InvalidCredentialsException;
use Elabftw\Interfaces\AuthInterface;
use Elabftw\Interfaces\ControllerInterface;
use Elabftw\Maps\Team;
use Elabftw\Models\Idps;
use Elabftw\Models\Teams;
use Elabftw\Services\AnonAuth;
use Elabftw\Services\LdapAuth;
use Elabftw\Services\LocalAuth;
use Elabftw\Services\LoginHelper;
use Elabftw\Services\MfaAuth;
use Elabftw\Services\MfaHelper;
use Elabftw\Services\SamlAuth;
use Elabftw\Services\TeamAuth;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * For all your authentication/login needs
 */
class LoginController implements ControllerInterface
{
    /** @var App $App */
    private $App;

    public function __construct(App $app)
    {
        $this->App = $app;
    }

    public function getResponse(): Response
    {
        // ENABLE MFA FOR OUR USER
        if ($this->App->Session->has('enable_mfa')) {
            // Only save if user didn't click Cancel button
            if ($this->App->Request->request->get('Submit') === 'submit') {
                $MfaHelper = new MfaHelper(
                    (int) $this->App->Users->userData['userid'],
                    $this->App->Session->get('mfa_secret'),
                );

                // check the input code against the secret stored in session
                if (!$MfaHelper->verifyCode($this->App->Request->get('mfa_code') ?? '')) {
                    throw new InvalidCredentialsException('The code you entered is not valid!');
                }

                // all good, save the secret in the database now that we now the user can authenticate against it
                $MfaHelper->saveSecret();
                $this->App->Session->getFlashBag()->add('ok', _('Two Factor Authentication is now enabled!'));
            } else {
                $this->App->Session->getFlashBag()->add('ko', _('Two Factor Authentication was not enabled!'));
            }
            $this->App->Session->remove('enable_mfa');
            $this->App->Session->remove('mfa_auth_required');
            $this->App->Session->remove('mfa_secret');

            return new RedirectResponse('../../ucp.php?tab=2');
        }

        // store the rememberme choice in session
        $this->App->Session->set('rememberme', false);
        if ($this->App->Request->request->has('rememberme')) {
            $this->App->Session->set('rememberme', true);
        }


        // get our Auth service and try to authenticate
        $authType = $this->App->Request->request->get('auth_type');
        $AuthResponse = $this->getAuthService($authType)->tryAuth();

        /////////
        // MFA //
        /////////
        // check if we need to do mfa auth too after a first successful authentication
        if ($AuthResponse->mfaSecret && !$AuthResponse->hasVerifiedMfa) {
            $this->App->Session->set('mfa_auth_required', true);
            $this->App->Session->set('mfa_secret', $AuthResponse->mfaSecret);
            // remember which user is authenticated
            $this->App->Session->set('auth_userid', $AuthResponse->userid);
            return new RedirectResponse('../../login.php');
        }
        if ($AuthResponse->hasVerifiedMfa) {
            $this->App->Session->remove('mfa_auth_required');
            $this->App->Session->remove('mfa_secret');
        }


        ////////////////////
        // TEAM SELECTION //
        ////////////////////
        // if the user is in several teams, we need to redirect to the team selection
        if ($AuthResponse->selectedTeam === null) {
            $this->App->Session->set('team_selection_required', true);
            $this->App->Session->set('team_selection', $AuthResponse->selectableTeams);
            $this->App->Session->set('auth_userid', $AuthResponse->userid);
            return new RedirectResponse('../../login.php');
        }

        // All good now we can login the user
        $LoginHelper = new LoginHelper($AuthResponse, $this->App->Session);
        $LoginHelper->login($this->App->Session->get('rememberme'));

        // cleanup
        $this->App->Session->remove('failed_attempt');
        $this->App->Session->remove('rememberme');
        $this->App->Session->remove('auth_userid');

        if ($this->App->Request->cookies->has('redirect')) {
            $location = $this->App->Request->cookies->get('redirect');
        } else {
            $location = '../../experiments.php';
        }
        return new RedirectResponse($location);
    }

    private function getAuthService(string $authType): AuthInterface
    {
        switch ($authType) {
            // AUTH WITH LDAP
            case 'ldap':
                return new LdapAuth($this->App->Config, $this->App->Request->request->get('email'), $this->App->Request->request->get('password'));

            // AUTH WITH LOCAL DATABASE
            case 'local':
                return new LocalAuth($this->App->Request->request->get('email'), $this->App->Request->request->get('password'));

            // AUTH WITH SAML
            case 'saml':
                return new SamlAuth($this->App->Config, new Idps(), (int) $this->App->Request->request->get('idpId'));

            // AUTH AS ANONYMOUS USER
            case 'anon':
                return new AnonAuth($this->App->Config, (int) $this->App->Request->request->get('team_id'));

            // AUTH in a team (after the team selection page)
            // we are already authenticated
            case 'team':
                return new TeamAuth($this->App->Session->get('auth_userid'), (int) $this->App->Request->request->get('selected_team'));

            // MFA AUTH
            case 'mfa':
                return new MfaAuth(
                    new MfaHelper(
                        (int) $this->App->Session->get('auth_userid'),
                        $this->App->Session->get('mfa_secret'),
                    ),
                    $this->App->Request->get('mfa_code') ?? '',
                );

            default:
                throw new ImproperActionException('Could not determine which authentication service to use.');
        }
    }
}