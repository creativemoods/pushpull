import React from 'react';
import Notices from './components/Notices';
import SettingsPane from './components/SettingsPane';
import DiffPane from './components/DiffPane';
import RepositoryPane from './components/RepositoryPane';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from 'react';
import { TabPanel } from '@wordpress/components';

const App = () => {
	const [tab, setTab] = useState('settings');

	const onSelect = ( tabName ) => {
		setTab(tabName);
	};

	return (
	<div>
		<h1 className='app-title'>{ __( 'PushPull Settings', 'pushpull' ) }</h1>
		<Notices/>
		<TabPanel
			className="my-tab-panel"
			activeClass="active-tab"
			onSelect={onSelect}
			tabs={[
				{
					name: 'settings',
					title: 'Settings',
					className: 'tab-one',
				},
				{
					name: 'diff',
					title: 'Diff viewer',
					className: 'tab-two',
				},
				{
					name: 'repo',
					title: 'Repository',
					className: 'tab-three',
				},
			]}
		>
			{ ( tab ) => <p>{ tab.title }</p> }
		</TabPanel>
		{tab === "settings" && <SettingsPane />}
		{tab === "diff" && <DiffPane />}
		{tab === "repo" && <RepositoryPane />}
        </div>
	);
}

export default App;
