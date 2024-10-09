import React from 'react';
import { useState, useEffect } from 'react';
import { SelectControl } from '@wordpress/components';
import ReactDiffViewer from 'react-diff-viewer-continued';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const DiffPane = () => {
	const [curPost, setCurPost] = useState("");
	const [curPostType, setCurPostType] = useState("");
	const [posts, setPosts] = useState([]);
	const [posttypes, setPosttypes] = useState([]);
	const [oldCode, setOldCode] = useState("");
	const [newCode, setNewCode] = useState("");

	useEffect( () => {
		if (curPost !== "" && curPostType !== "") {
			apiFetch({
				path: addQueryArgs('/pushpull/v1/diff', { 'post_name': curPost, 'post_type': curPostType } ),
			}).then((data) => {
				setOldCode(data['local']);
				setNewCode(data['remote']);
			}).catch((error) => {
				console.error(error);
			});
		}
	}, [curPost] );

	useEffect( () => {
		apiFetch({
			path: addQueryArgs('/pushpull/v1/posts', { 'post_type': curPostType } ),
		}).then((data) => {
			setPosts(data);
		}).catch((error) => {
			console.error(error);
		});
	}, [curPostType] );

	useEffect( () => {
		apiFetch({
			path: '/pushpull/v1/posttypes',
		}).then((data) => {
			setPosttypes(data);
		}).catch((error) => {
			console.error(error);
		});
	}, [] );

	const onSelectPost = ( post ) => {
		setCurPost(post);
	};

	const onSelectPostType = ( posttype ) => {
		setCurPostType(posttype);
	};

	return (<>
			<SelectControl
				label={__('Choose post type', 'pushpull')}
				value={curPostType}
				onChange={onSelectPostType}
				options={Object.entries(posttypes).map(([k,v]) => { return { label: v, value: k}; })}
			/>
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
	);
}

export default DiffPane;
