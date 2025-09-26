<?php declare(strict_types = 1);
 
namespace Modules\BGmotHosts;
 
use APP;
use CControllerHost;
use CControllerProblem;
use CControllerLatest;
use Modules\BGmotHosts\Actions\CControllerBGHost;
use CControllerTabFilterProfileUpdate;
use CController as CAction;
 
class Module extends \Zabbix\Core\CModule {
	/**
	 * Initialize module.
	 */
	public function init(): void {
		// Initialize main menu (CMenu class instance).
		APP::Component()->get('menu.main')
			->findOrAdd(_('Monitoring'))
				->getSubmenu()
					->insertAfter('Hosts', (new \CMenuItem(_('Hosts tree')))
						->setAction('bghost.view')
					);
	}
 
	/**
	 * Event handler, triggered before executing the action.
	 *
	 * @param CAction $action  Action instance responsible for current request.
	 */
	public function onBeforeAction(CAction $action): void {
		CControllerTabFilterProfileUpdate::$namespaces = [
                CControllerHost::FILTER_IDX => CControllerHost::FILTER_FIELDS_DEFAULT,
				CControllerBGHost::FILTER_IDX => CControllerBGHost::FILTER_FIELDS_DEFAULT,
				CControllerProblem::FILTER_IDX => CControllerProblem::FILTER_FIELDS_DEFAULT,
                CControllerLatest::FILTER_IDX => CControllerLatest::FILTER_FIELDS_DEFAULT
        ];
	}
 
	/**
	 * Event handler, triggered on application exit.
	 *
	 * @param CAction $action  Action instance responsible for current request.
	 */
	public function onTerminate(CAction $action): void {
	}
}
?>
