import React from 'react';
import Dashboard from './components/Dashboard';
import Notices from './components/Notices';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from 'react';
import { Button, Card, CardBody, TextControl, TabPanel, SelectControl } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import ReactDiffViewer from 'react-diff-viewer-continued';
import { addQueryArgs } from '@wordpress/url';

const App = () => {
	const [host, setHost] = useState('');
	const [oauthToken, setOauthToken] = useState('');
	const [repository, setRepository] = useState('');
	const {createSuccessNotice} = useDispatch( noticesStore );
	const [tab, setTab] = useState('settings');
	const [posts, setPosts] = useState([]);
	const [curPost, setCurPost] = useState("");
	const [oldCode, setOldCode] = useState("toto");
	const [newCode, setNewCode] = useState("tata");

	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/settings',
		}).then((data) => {
			setHost(data['host']);
			setOauthToken(data['oauth-token']);
			setRepository(data['repository']);
		}).catch((error) => {
			console.error(error);
		});
		apiFetch({
			path: '/pushpull/v1/posts',
		}).then((data) => {
			setPosts(data);
		}).catch((error) => {
			console.error(error);
		});
	}, [] );

	useEffect( () => {
		apiFetch({
			path: addQueryArgs('/pushpull/v1/diff', { 'post_name': curPost } ),
		}).then((data) => {
			setOldCode(data['local']);
			setNewCode(data['remote']);
		}).catch((error) => {
			console.error(error);
		});
	}, [curPost] );

	const onSelect = ( tabName ) => {
		setTab(tabName);
	};

	const onSelectPost = ( post ) => {
		setCurPost(post);
	};

	const handleSubmit = (event) => {
		event.preventDefault();
		apiFetch({
			path: '/pushpull/v1/settings/',
			method: 'POST',
			data: {
				'host': host,
				'oauth-token': oauthToken,
				'repository': repository,
			},
		}).then((data) => {
			createSuccessNotice(__('Settings saved.'), {
				isDismissible: true,
			});
		}).catch((error) => {
			console.error(error);
		});
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
			]}
		>
			{ ( tab ) => <p>{ tab.title }</p> }
		</TabPanel>
		{tab === "settings" && (<form onSubmit={handleSubmit}>
	        <Card>
	            <CardBody>
                        <TextControl
                            label={ __( 'Git API', 'pushpull' ) }
                            help={ __( 'The Git API URL.', 'pushpull' ) }
			    value={host}
			    onChange={setHost}
                        />
                        <TextControl
                            label={ __( 'Project', 'pushpull' ) }
                            help={ __( 'The Git repository to commit to.', 'pushpull' ) }
			    value={oauthToken}
			    onChange={setOauthToken}
                        />
                        <TextControl
                            label={ __( 'Oauth Token', 'pushpull' ) }
                            help={ __( 'A personal oauth token with public_repo scope.', 'pushpull' ) }
			    value={repository}
			    onChange={setRepository}
                        />
			<Button variant="primary" type="submit">
				{ __( 'Save', 'pushpull' ) }
			</Button>
                    </CardBody>
	        </Card>
	    </form>)}
		{tab === "diff" && (
			<>
			<SelectControl
				label={__('Choose post', 'pushpull')}
				value={curPost}
				onChange={onSelectPost}
				options={Object.entries(posts).map(([k,v]) => { return { label: v, value: k}; })}
			/>
			<ReactDiffViewer
				oldValue={oldCode}
				newValue={newCode}
				splitView={true}
				extraLinesSurroundingDiff={0}
				leftTitle={"Local"}
				rightTitle={"Remote"}
			/>
			</>
		)}
        </div>
	);
}

export default App;
