<?php
/*
** Zabbix
** Copyright (C) 2001-2016 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


$divTabs = new CTabView();
if (!isset($_REQUEST['form_refresh'])) {
	$divTabs->setSelected(0);
}

$host = getRequest('template_name', '');
$visiblename = getRequest('visiblename', '');
$newgroup = getRequest('newgroup', '');
$templateIds = getRequest('templates', []);
$clear_templates = getRequest('clear_templates', []);
$macros = getRequest('macros', []);

$frm_title = _('Template');

if ($data['templateId'] != 0) {
	$frm_title .= SPACE.' ['.$this->data['dbTemplate']['name'].']';
}
$frmHost = (new CForm())
	->setName('templatesForm')
	->addVar('form', $data['form'])
	->addVar('groupid', $data['groupId']);

if ($data['templateId'] != 0) {
	$frmHost->addVar('templateid', $data['templateId']);
}

if ($data['templateId'] != 0 && !hasRequest('form_refresh')) {
	$host = $this->data['dbTemplate']['host'];
	$visiblename = $this->data['dbTemplate']['name'];

	// display empty visible name if equal to host name
	if ($visiblename === $host) {
		$visiblename = '';
	}

	$macros = $this->data['dbTemplate']['macros'];
	$templateIds = $this->data['original_templates'];
}

if ($data['show_inherited_macros']) {
	$macros = mergeInheritedMacros($macros, getInheritedMacros($templateIds));
}
$macros = array_values(order_macros($macros, 'macro'));

$clear_templates = array_intersect($clear_templates, array_keys($this->data['original_templates']));
$clear_templates = array_diff($clear_templates, array_keys($templateIds));
natcasesort($templateIds);
$frmHost->addVar('clear_templates', $clear_templates);

// TEMPLATE WIDGET {
$templateList = (new CFormList('hostlist'))
	->addRow(_('Template name'), (new CTextBox('template_name', $host, false, 128))
		->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		->setAttribute('autofocus', 'autofocus')
	)
	->addRow(_('Visible name'), (new CTextBox('visiblename', $visiblename, false, 128))
		->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	);

$groupsTB = new CTweenBox($frmHost, 'groups', $data['groupIds'], 10);

if ($data['form'] === 'update') {
	// Add existing template groups to list and, depending on permissions show name as enabled or disabled.

	$groupsInList = [];

	foreach ($data['groupsAll'] as $group) {
		if (isset($data['groupIds'][$group['groupid']])) {
			$groupsTB->addItem($group['groupid'], $group['name'], true,
				isset($data['groupsAllowed'][$group['groupid']])
			);
			$groupsInList[] = $group['groupid'];
		}
	}

	// Add other host groups that user has permissions to, if not yet added to list.
	foreach ($data['groupsAllowed'] as $group) {
		if (!in_array($group['groupid'], $groupsInList)) {
			$groupsTB->addItem($group['groupid'], $group['name']);
		}
	}
}
else {
	/*
	 * When cloning a template or creating a new one, don't show read-only host groups in left box,
	 * but show empty or posted groups in case of an error
	 */

	foreach ($data['groupsAllowed'] as $group) {
		$groupsTB->addItem($group['groupid'], $group['name']);
	}
}

$templateList->addRow(_('Groups'), $groupsTB->get(_('In groups'), _('Other groups')));

// FORM ITEM : new group text box [  ]
$new_group = (new CTextBox('newgroup', $newgroup))
	->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->setAttribute('maxlength', 64);
$new_group_label = _('New group');
if (CWebUser::$data['type'] != USER_TYPE_SUPER_ADMIN) {
	$new_group_label .= ' '._('(Only super admins can create groups)');
	$new_group->setReadonly(true);
}
$templateList->addRow(new CLabel($new_group_label, 'newgroup'),
	(new CSpan($new_group))->addClass(ZBX_STYLE_FORM_NEW_GROUP)
);

// FORM ITEM : linked Hosts tween box [  ] [  ]
$cmbGroups = new CComboBox('twb_groupid', $data['twb_groupid'], 'submit()');
foreach ($data['groupsAllowed'] as $group) {
	$cmbGroups->addItem($group['groupid'], $group['name']);
}

$hostsTB = new CTweenBox($frmHost, 'hosts', $data['hostIdsLinkedTo'], 20);

foreach ($data['hostsAllowedToAdd'] as $host) {
	if (isset($data['hostIdsLinkedTo'][$host['hostid']])) {
		continue;
	}
	$hostsTB->addItem($host['hostid'], $host['name']);
}

foreach ($data['hostsAll'] as $host) {
	$hostsTB->addItem($host['hostid'], $host['name'], true, isset($data['hostsAllowed'][$host['hostid']]));
}

$templateList->addRow(_('Hosts / templates'), $hostsTB->Get(_('In'), [
	_('Other | group').SPACE,
	$cmbGroups
]));

$templateList->addRow(_('Description'),
	(new CTextArea('description', $this->data['description']))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
);

// FULL CLONE {
if ($data['form'] === 'full_clone') {
	// template applications
	$templateApps = API::Application()->get([
		'hostids' => $data['templateId'],
		'inherited' => false,
		'output' => API_OUTPUT_EXTEND,
		'preservekeys' => true
	]);
	if (!empty($templateApps)) {
		$applicationsList = [];
		foreach ($templateApps as $tplAppId => $templateApp) {
			$applicationsList[$tplAppId] = $templateApp['name'];
		}
		order_result($applicationsList);

		$listBox = (new CListBox('applications', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($applicationsList);
		$templateList->addRow(_('Applications'), $listBox);
	}

	// items
	$hostItems = API::Item()->get([
		'hostids' => $data['templateId'],
		'inherited' => false,
		'filter' => ['flags' => ZBX_FLAG_DISCOVERY_NORMAL],
		'output' => ['itemid', 'key_', 'name', 'hostid']
	]);

	if ($hostItems) {
		$hostItems = CMacrosResolverHelper::resolveItemNames($hostItems);

		$itemsList = [];
		foreach ($hostItems as $hostItem) {
			$itemsList[$hostItem['itemid']] = $hostItem['name_expanded'];
		}
		order_result($itemsList);

		$listBox = (new CListBox('items', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($itemsList);

		$templateList->addRow(_('Items'), $listBox);
	}

// Triggers
	$hostTriggers = API::Trigger()->get([
		'inherited' => false,
		'hostids' => $data['templateId'],
		'output' => API_OUTPUT_EXTEND,
		'filter' => ['flags' => [ZBX_FLAG_DISCOVERY_NORMAL]]
	]);
	if (!empty($hostTriggers)) {
		$triggersList = [];
		foreach ($hostTriggers as $hostTrigger) {
			$triggersList[$hostTrigger['triggerid']] = $hostTrigger['description'];
		}
		order_result($triggersList);

		$listBox = (new CListBox('triggers', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($triggersList);

		$templateList->addRow(_('Triggers'), $listBox);
	}

// Graphs
	$hostGraphs = API::Graph()->get([
		'inherited' => false,
		'hostids' => $data['templateId'],
		'filter' => ['flags' => [ZBX_FLAG_DISCOVERY_NORMAL]],
		'output' => API_OUTPUT_EXTEND,
	]);
	if (!empty($hostGraphs)) {
		$graphsList = [];
		foreach ($hostGraphs as $hostGraph) {
			$graphsList[$hostGraph['graphid']] = $hostGraph['name'];
		}
		order_result($graphsList);

		$listBox = (new CListBox('graphs', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($graphsList);

		$templateList->addRow(_('Graphs'), $listBox);
	}

	// discovery rules
	$hostDiscoveryRules = API::DiscoveryRule()->get([
		'inherited' => false,
		'hostids' => $data['templateId'],
		'output' => API_OUTPUT_EXTEND,
	]);

	if ($hostDiscoveryRules) {
		$hostDiscoveryRules = CMacrosResolverHelper::resolveItemNames($hostDiscoveryRules);

		$discoveryRuleList = [];
		foreach ($hostDiscoveryRules as $discoveryRule) {
			$discoveryRuleList[$discoveryRule['itemid']] = $discoveryRule['name_expanded'];
		}
		order_result($discoveryRuleList);
		$hostDiscoveryRuleids = array_keys($discoveryRuleList);

		$listBox = (new CListBox('discoveryRules', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($discoveryRuleList);

		$templateList->addRow(_('Discovery rules'), $listBox);

		// item prototypes
		$hostItemPrototypes = API::ItemPrototype()->get([
			'hostids' => $data['templateId'],
			'discoveryids' => $hostDiscoveryRuleids,
			'inherited' => false,
			'output' => API_OUTPUT_EXTEND,
		]);

		if ($hostItemPrototypes) {
			$hostItemPrototypes = CMacrosResolverHelper::resolveItemNames($hostItemPrototypes);

			$prototypeList = [];
			foreach ($hostItemPrototypes as $itemPrototype) {
				$prototypeList[$itemPrototype['itemid']] = $itemPrototype['name_expanded'];
			}
			order_result($prototypeList);

			$listBox = (new CListBox('itemsPrototypes', null, 8))
				->setAttribute('disabled', 'disabled')
				->addItems($prototypeList);

			$templateList->addRow(_('Item prototypes'), $listBox);
		}

// Trigger prototypes
		$hostTriggerPrototypes = API::TriggerPrototype()->get([
			'hostids' => $data['templateId'],
			'discoveryids' => $hostDiscoveryRuleids,
			'inherited' => false,
			'output' => API_OUTPUT_EXTEND
		]);
		if (!empty($hostTriggerPrototypes)) {
			$prototypeList = [];
			foreach ($hostTriggerPrototypes as $triggerPrototype) {
				$prototypeList[$triggerPrototype['triggerid']] = $triggerPrototype['description'];
			}
			order_result($prototypeList);

			$listBox = (new CListBox('triggerprototypes', null, 8))
				->setAttribute('disabled', 'disabled')
				->addItems($prototypeList);

			$templateList->addRow(_('Trigger prototypes'), $listBox);
		}

// Graph prototypes
		$hostGraphPrototypes = API::GraphPrototype()->get([
			'hostids' => $data['templateId'],
			'discoveryids' => $hostDiscoveryRuleids,
			'inherited' => false,
			'output' => API_OUTPUT_EXTEND,
		]);
		if (!empty($hostGraphPrototypes)) {
			$prototypeList = [];
			foreach ($hostGraphPrototypes as $graphPrototype) {
				$prototypeList[$graphPrototype['graphid']] = $graphPrototype['name'];
			}
			order_result($prototypeList);

			$listBox = (new CListBox('graphPrototypes', null, 8))
				->setAttribute('disabled', 'disabled')
				->addItems($prototypeList);

			$templateList->addRow(_('Graph prototypes'), $listBox);
		}
	}

	// screens
	$screens = API::TemplateScreen()->get([
		'inherited' => false,
		'templateids' => $data['templateId'],
		'output' => ['screenid', 'name'],
	]);
	if (!empty($screens)) {
		$screensList = [];
		foreach ($screens as $screen) {
			$screensList[$screen['screenid']] = $screen['name'];
		}
		order_result($screensList);

		$listBox = (new CListBox('screens', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($screensList);

		$templateList->addRow(_('Screens'), $listBox);
	}

	// web scenarios
	$httpTests = API::HttpTest()->get([
		'output' => ['httptestid', 'name'],
		'hostids' => $data['templateId'],
		'inherited' => false
	]);

	if ($httpTests) {
		$httpTestList = [];

		foreach ($httpTests as $httpTest) {
			$httpTestList[$httpTest['httptestid']] = $httpTest['name'];
		}

		order_result($httpTestList);

		$listBox = (new CListBox('httpTests', null, 8))
			->setAttribute('disabled', 'disabled')
			->addItems($httpTestList);
		$templateList->addRow(_('Web scenarios'), $listBox);
	}
}

$cloneOrFullClone = ($data['form'] === 'clone' || $data['form'] === 'full_clone');

$divTabs->addTab('templateTab', _('Template'), $templateList);
// FULL CLONE }

// } TEMPLATE WIDGET

// TEMPLATES{
$tmplList = new CFormList();

$ignoredTemplates = [];

$linkedTemplateTable = (new CTable())
	->setAttribute('style', 'width: 100%;')
	->setHeader([_('Name'), _('Action')]);

foreach ($data['linkedTemplates'] as $template) {
	$tmplList->addVar('templates[]', $template['templateid']);
	$templateLink = (new CLink($template['name'], 'templates.php?form=update&templateid='.$template['templateid']))
		->setTarget('_blank');

	$linkedTemplateTable->addRow([
		$templateLink,
		(new CCol(
			new CHorList([
				(new CSubmit('unlink['.$template['templateid'].']', _('Unlink')))->addClass(ZBX_STYLE_BTN_LINK),
				(array_key_exists($template['templateid'], $data['original_templates']) && !$cloneOrFullClone)
					? (new CSubmit('unlink_and_clear['.$template['templateid'].']', _('Unlink and clear')))
						->addClass(ZBX_STYLE_BTN_LINK)
					: null
			])
		))->addClass(ZBX_STYLE_NOWRAP)
	], null, 'conditions_'.$template['templateid']);

	$ignoredTemplates[$template['templateid']] = $template['name'];
}

$tmplList->addRow(_('Linked templates'),
	(new CDiv($linkedTemplateTable))
		->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
		->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
);

// create new linked template table
$newTemplateTable = (new CTable())
	->addRow([
		(new CMultiSelect([
			'name' => 'add_templates[]',
			'objectName' => 'templates',
			'ignored' => $ignoredTemplates,
			'popup' => [
				'parameters' => 'srctbl=templates&srcfld1=hostid&srcfld2=host&dstfrm='.$frmHost->getName().
					'&dstfld1=add_templates_&templated_hosts=1&multiselect=1'
			]
		]))->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	])
	->addRow([(new CSubmit('add_template', _('Add')))->addClass(ZBX_STYLE_BTN_LINK)]);

$tmplList->addRow(_('Link new templates'),
	(new CDiv($newTemplateTable))
		->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR)
		->setAttribute('style', 'min-width: '.ZBX_TEXTAREA_BIG_WIDTH.'px;')
);

$divTabs->addTab('tmplTab', _('Linked templates'), $tmplList);
// } TEMPLATES

// macros
if (!$macros) {
	$macro = ['macro' => '', 'value' => ''];
	if ($data['show_inherited_macros']) {
		$macro['type'] = MACRO_TYPE_HOSTMACRO;
	}
	$macros[] = $macro;
}

$macrosView = new CView('hostmacros', [
	'macros' => $macros,
	'show_inherited_macros' => $data['show_inherited_macros'],
	'is_template' => true,
	'readonly' => false
]);
$divTabs->addTab('macroTab', _('Macros'), $macrosView->render());


// Footer
if ($data['templateId'] != 0 && $data['form'] !== 'full_clone') {
	$divTabs->setFooter(makeFormFooter(
		new CSubmit('update', _('Update')),
		[
			new CSubmit('clone', _('Clone')),
			new CSubmit('full_clone', _('Full clone')),
			new CButtonDelete(_('Delete template?'), url_param('form').url_param('templateid').url_param('groupid')),
			new CButtonQMessage(
				'delete_and_clear',
				_('Delete and clear'),
				_('Delete and clear template? (Warning: all linked hosts will be cleared!)'),
				url_param('form').url_param('templateid').url_param('groupid')
			),
			new CButtonCancel(url_param('groupid'))
		]
	));
}
else {
	$divTabs->setFooter(makeFormFooter(
		new CSubmit('add', _('Add')),
		[new CButtonCancel(url_param('groupid'))]
	));
}

$frmHost->addItem($divTabs);

return $frmHost;
