import React from 'react';
import { useState, useEffect } from 'react';
import { Button, Card, CardBody, CheckboxControl, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import PropTypes from 'prop-types';
import { addQueryArgs } from '@wordpress/url';
import Stack from '@mui/material/Stack';
import { Button as MUIButton, FormHelperText, Select as MUISelect, MenuItem, Grid2 } from '@mui/material';

const SettingsPane = (props) => {
	const { selectedPostTypes, setSelectedPostTypes, setIsModalOpen } = props;
	const {createSuccessNotice, createErrorNotice} = useDispatch( noticesStore );
	const [providers, setProviders] = useState([]);
	const [provider, setProvider] = useState('');
	const [host, setHost] = useState('');
	const [repository, setRepository] = useState('');
	const [oauthToken, setOauthToken] = useState('');
	const [branch, setBranch] = useState('');
	const [branches, setBranches] = useState([]);
	const [postTypes, setPostTypes] = useState({});
	const [tables, setTables] = useState([]);
	const [selectedTables, setSelectedTables] = useState([]);
    const [testColor, setTestColor] = useState('primary');

	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/settings',
		}).then((data) => {
			setProvider(data['provider']);
			setHost(data['host']);
			setOauthToken(data['oauth-token']);
			setRepository(data['repository']);
			setBranch(data['branch']);
			setSelectedPostTypes(data['posttypes']);
			setSelectedTables(data['tables']);
		}).catch((error) => {
			console.error(error);
		});
		apiFetch({
			path: '/pushpull/v1/posttypes',
		}).then((data) => {
			setPostTypes(data);
		}).catch((error) => {
			console.error(error);
		});
		apiFetch({
			path: '/pushpull/v1/tables',
		}).then((data) => {
			setTables(data);
		}).catch((error) => {
			console.error(error);
		});
		apiFetch({
			path: '/pushpull/v1/providers',
		}).then((data) => {
			setProviders(data);
		}).catch((error) => {
			console.error(error);
		});
	}, [] );

	useEffect( () => {
		if (provider && provider !== "custom" && providers.length > 0) {
			setHost(providers.find((p) => p.id === provider).url);
		}
	}, [provider]);

	useEffect( () => {
		if (branches.length > 0) {
			setBranch(branches[0].name);
		}
	}, [branches]);

	const setCheckedPostType = (k, v) => {
		var arr = [...selectedPostTypes];
		if (v) {
			// Add k
			var index = arr.indexOf(k);
			if (index === -1) {
				arr.push(k);
				setSelectedPostTypes(arr);
			}
		} else {
			// Remove k
			var index = arr.indexOf(k);
			if (index !== -1) {
				arr.splice(index, 1);
				setSelectedPostTypes(arr);
			}
		}
	};

	const setCheckedTable = (t, v) => {
		var arr = [...selectedTables];
		if (v) {
			// Add t
			var index = arr.indexOf(t);
			if (index === -1) {
				arr.push(t);
				setSelectedTables(arr);
			}
		} else {
			// Remove t
			var index = arr.indexOf(t);
			if (index !== -1) {
				arr.splice(index, 1);
				setSelectedTables(arr);
			}
		}
	};

	const handleSubmit = (event) => {
		event.preventDefault();
		apiFetch({
			path: '/pushpull/v1/settings/',
			method: 'POST',
			data: {
				'provider': provider,
				'host': host,
				'oauth-token': oauthToken,
				'branch': branch,
				'repository': repository,
				'posttypes': selectedPostTypes,
				'tables': selectedTables,
			},
		}).then((data) => {
			createSuccessNotice(__('Settings saved.'), {
				isDismissible: true,
			});
			setTestColor('primary');
		}).catch((error) => {
			console.error(error);
		});
	};

	const handleTest = (event) => {
		setIsModalOpen(true);
		apiFetch({
			path: addQueryArgs('/pushpull/v1/branches', {
				'provider': provider,
				'url': host,
				'token': oauthToken,
				'repository': repository,
			}),
			}).then((data) => {
				setIsModalOpen(false);
				setBranches(data);
				setTestColor('success');
		}).catch((error) => {
			setIsModalOpen(false);
			createErrorNotice(error.message, {
				isDismissible: true,
			});
			setTestColor('error');
		});
	};

	const handleChangeProvider = (newProvider) => {
		setProvider(newProvider);
	};

	const handleChangeBranch = (e) => {
		setBranch(e.target.value);
	};

	return (
		<form onSubmit={handleSubmit}>
		<Card>
			<CardBody>
				<SelectControl
					label={ __( 'Git provider', 'pushpull' ) }
					help={ __( 'The Git provider.', 'pushpull' ) }
					value={ provider }
					options={ providers.map((p) => { return {label: p.name, value: p.id}})}
					onChange={ handleChangeProvider }
				/>
				<TextControl
					label={ __( 'Git API', 'pushpull' ) }
					help={ __( 'The Git API URL.', 'pushpull' ) }
					value={ host }
					onChange={ setHost }
					disabled={ provider && providers.length > 0 ? providers.find((p) => p.id === provider).disabledurl : true }
				/>
				<TextControl
					label={ __( 'Oauth Token', 'pushpull' ) }
					help={ __( 'A personal oauth token with public_repo scope.', 'pushpull' ) }
					value={oauthToken}
					onChange={setOauthToken}
				/>
				<TextControl
					label={ __( 'Project', 'pushpull' ) }
					help={ __( 'The Git repository to commit to.', 'pushpull' ) }
					value={repository}
					onChange={setRepository}
				/>
				<Stack direction="row" spacing={2}>
					<MUIButton
						variant="contained"
						onClick={handleTest}
						color={testColor}
					>
						{ __( 'Test', 'pushpull' ) }
					</MUIButton>
					<MUISelect
						label={ __( 'Branch', 'pushpull' ) }
						value={ branches.length > 0 ? branch : '' }
						onChange={ handleChangeBranch }
					>
						{branches.map((b) => (
							<MenuItem key={b.name} value={b.name}>{b.name}</MenuItem>
						))}
					</MUISelect>
					<FormHelperText>{__( 'The branch to commit to.', 'pushpull' )}</FormHelperText>
				</Stack>
			</CardBody>
		</Card>
		<Card>
			<CardBody>
				<h2>{ __( 'Post types', 'pushpull' ) }</h2>
				<Grid2 container rowSpacing={2} columnSpacing={2}>
				{Object.entries(postTypes).map(([k, v]) => (<Grid2 size={3} key={k}><CheckboxControl
					__nextHasNoMarginBottom
					label={v}
					key={k}
					//help="Manage {v} post type ?"
					checked={ selectedPostTypes.includes(k) }
					onChange={ (v) => setCheckedPostType(k, v) }
					/></Grid2>))}
				</Grid2>
				<h2>{ __( 'Custom tables', 'pushpull' ) }</h2>
				<Grid2 container rowSpacing={2} columnSpacing={2}>
				{Object.entries(tables).map(([plugin, tables]) => Object.entries(tables).map(([table, v]) => (<Grid2 size={3} key={plugin+"-"+table}><CheckboxControl
					__nextHasNoMarginBottom
					label={plugin+" / "+table}
					key={plugin+"-"+table}
					//help="Manage {v} post type ?"
					checked={ selectedTables.includes(plugin+"-"+table) }
					onChange={ (v) => setCheckedTable(plugin+"-"+table, v) }
					/></Grid2>
				)))}
				</Grid2>
				<p>&nbsp;</p>
				<MUIButton variant="contained" type="submit">
					{ __( 'Save', 'pushpull' ) }
				</MUIButton>
			</CardBody>
		</Card>
	</form>
	);
}

SettingsPane.propTypes = {
	selectedPostTypes: PropTypes.array.isRequired,
	setSelectedPostTypes: PropTypes.func.isRequired,
};

export default SettingsPane;
