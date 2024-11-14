import React from 'react';
import { useState, useEffect } from 'react';
import { SelectControl } from '@wordpress/components';
import ReactDiffViewer from 'react-diff-viewer-continued';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import PropTypes from 'prop-types';

const DiffPane = (props) => {
	const { curPost, setCurPost, curPostType, setCurPostType, selectedPostTypes } = props;
	const [posts, setPosts] = useState({});
	const [oldCode, setOldCode] = useState("");
	const [newCode, setNewCode] = useState("");

	useEffect( () => {
		if (curPost !== "" && curPostType !== "" && curPost !== "Please select a post" && curPostType !== "Please select a post type") {
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
		if (curPostType !== "Please select a post type") {
			apiFetch({
				path: addQueryArgs('/pushpull/v1/posts', { 'post_type': curPostType } ),
			}).then((data) => {
				setPosts({ "Please select a post": "Please select a post", ...data });
			}).catch((error) => {
				console.error(error);
			});
		}
	}, [curPostType] );

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
				options={Object.entries(selectedPostTypes).map(([k,v]) => { return { label: v, value: v}; })}
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

DiffPane.propTypes = {
  curPost: PropTypes.string.isRequired,
  setCurPost: PropTypes.func.isRequired,
  curPostType: PropTypes.string.isRequired,
  setCurPostType: PropTypes.func.isRequired,
  selectedPostTypes: PropTypes.array.isRequired,
};

export default DiffPane;
