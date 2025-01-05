import React from 'react';
import Notices from './components/Notices';
import SettingsPane from './components/SettingsPane';
import RepositoryPane from './components/RepositoryPane';
import SyncPane from './components/SyncPane';
import DeployPane from './components/DeployPane';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import Tabs from '@mui/material/Tabs';
import Tab from '@mui/material/Tab';
import Box from '@mui/material/Box';
import { Button as MUIButton } from '@mui/material';

function CustomTabPanel(props) {
	const { children, value, index, ...other } = props;

	return (
		<div
		role="tabpanel"
		hidden={value !== index}
		id={`simple-tabpanel-${index}`}
		aria-labelledby={`simple-tab-${index}`}
		{...other}
		>
		{value === index && <Box sx={{ p: 3 }}>{children}</Box>}
		</div>
	);
}

function a11yProps(index) {
	return {
		id: `simple-tab-${index}`,
		'aria-controls': `simple-tabpanel-${index}`,
	};
}

// TODO Move to Typescript
const App = () => {
	const [tab, setTab] = useState('settings');
	const [selectedPostTypes, setSelectedPostTypes] = useState([]);
	
	const onSelect = ( event, tabName ) => {
		setTab(tabName);
	};
	
	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/settings',
		}).then((data) => {
			if (!selectedPostTypes.includes('Please select a post type')) {
				setSelectedPostTypes(['Please select a post type', ...data['posttypes']]);
			} else {
				setSelectedPostTypes(data['posttypes']);
			}
		}).catch((error) => {
			console.error(error);
		});
	}, [] );
	
	return (
		<div>
		<h1 className='app-title'>{ __( 'PushPull Settings', 'pushpull' ) }</h1>
		<Notices/>
		<Box sx={{ width: '100%' }}>
		<Box sx={{ borderBottom: 1, borderColor: 'divider' }}>
		<Tabs value={tab} onChange={onSelect} aria-label="Main tabs">
			<Tab label="Settings" value='settings' {...a11yProps('settings')} />
			<Tab label="Repository" value='repo' {...a11yProps('repo')} />
			<Tab label="Sync" value='sync' {...a11yProps('sync')} />
			<Tab label="Deploy" value='deploy' {...a11yProps('deploy')} />
		</Tabs>
		</Box>
		<CustomTabPanel value={tab} index='settings'>
		<SettingsPane selectedPostTypes={selectedPostTypes} setSelectedPostTypes={setSelectedPostTypes} />
		</CustomTabPanel>
		<CustomTabPanel value={tab} index='repo'>
		{tab === "repo" && <RepositoryPane />}
		</CustomTabPanel>
		<CustomTabPanel value={tab} index='sync'>
		{tab === "sync" && <SyncPane />}
		</CustomTabPanel>
		<CustomTabPanel value={tab} index='deploy'>
		{tab === "deploy" && <DeployPane />}
		</CustomTabPanel>
		</Box>
		</div>
	);
}

export default App;
