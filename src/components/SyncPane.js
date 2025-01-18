import React from 'react';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { DataGrid } from '@mui/x-data-grid';
import { darken, lighten, styled } from '@mui/material/styles';
import ArrowCircleRightIcon from '@mui/icons-material/ArrowCircleRight';
import ArrowCircleLeftIcon from '@mui/icons-material/ArrowCircleLeft';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { Button as MUIButton, Grid2 } from '@mui/material';

const getBackgroundColor = (color, theme, coefficient) => ({
  backgroundColor: darken(color, coefficient),
  ...theme.applyStyles('light', {
    backgroundColor: lighten(color, coefficient),
  }),
});

const StyledDataGrid = styled(DataGrid)(({ theme }) => ({
  '& .super-app-theme--notlocal': {
    ...getBackgroundColor(theme.palette.info.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.info.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.info.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.info.main, theme, 0.4),
      },
    },
  },
  '& .super-app-theme--identical': {
    ...getBackgroundColor(theme.palette.success.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.success.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.success.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.success.main, theme, 0.4),
      },
    },
  },
  '& .super-app-theme--notremote': {
    ...getBackgroundColor(theme.palette.warning.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.warning.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.warning.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.warning.main, theme, 0.4),
      },
    },
  },
  '& .super-app-theme--different': {
    ...getBackgroundColor(theme.palette.error.main, theme, 0.7),
    '&:hover': {
      ...getBackgroundColor(theme.palette.error.main, theme, 0.6),
    },
    '&.Mui-selected': {
      ...getBackgroundColor(theme.palette.error.main, theme, 0.5),
      '&:hover': {
        ...getBackgroundColor(theme.palette.error.main, theme, 0.4),
      },
    },
  },
}));

const SyncPane = (props) => {
  const { setIsModalOpen } = props;
	const {createSuccessNotice, createWarningNotice, createErrorNotice} = useDispatch( noticesStore );
	const [localCommits, setLocalCommits] = useState([]);
	const [remoteCommits, setRemoteCommits] = useState([]);
  const [status, setStatus] = useState({'localLatestCommitHash': null, 'remoteLatestCommitHash': null, 'status': 'unknown'});

  const onClickPull = (event) => {
    setIsModalOpen(true);
    apiFetch({
      path: '/pushpull/v1/sync/pull',
      method: 'POST',
      data: {},
    }).then((data) => {
      setIsModalOpen(false);
      createSuccessNotice(__('Repository pulled successfully.'), {
        isDismissible: true,
      });
      // Refresh the page to reflect the changes
      initializePane();
    }).catch((error) => {
      setIsModalOpen(false);
      createErrorNotice(__('Error pulling repository: '+error.message), {
        isDismissible: true,
      });
      initializePane();
    });
  };

  const onClickPush = (event) => {
    setIsModalOpen(true);
    apiFetch({
      path: '/pushpull/v1/sync/push',
      method: 'POST',
      data: {},
    }).then((data) => {
      setIsModalOpen(false);
      createSuccessNotice(__('Repository pushed successfully.'), {
        isDismissible: true,
      });
      initializePane();
    }).catch((error) => {
      setIsModalOpen(false);
      createErrorNotice(__('Error pushing repository: '+error.message), {
        isDismissible: true,
      });
      initializePane();
    });
  };

  const localcolumns = [
    {
      field: 'id',
      headerName: 'Commit ID',
      width: 110,
      editable: false,
    },
    {
      field: 'author',
      headerName: 'Author',
      width: 140,
      editable: false,
    },
    {
      field: 'timestamp',
      headerName: 'Committed date',
      width: 140,
      editable: false,
    },
    {
      field: 'message',
      headerName: 'Message',
      width: 140,
      editable: false,
    },
  ];

  const remotecolumns = [
    {
      field: 'short_id',
      headerName: 'Commit ID',
      width: 110,
      editable: false,
    },
    {
      field: 'author_name',
      headerName: 'Author',
      width: 140,
      editable: false,
    },
    {
      field: 'committed_date',
      headerName: 'Committed date',
      width: 140,
      editable: false,
    },
    {
      field: 'title',
      headerName: 'Message',
      width: 140,
      editable: false,
    },
  ];

  const initializePane = () => {
		apiFetch({
			path: '/pushpull/v1/sync/localcommits',
		}).then((data) => {
			setLocalCommits(data.reverse());
		}).catch((error) => {
			console.error(error);
		});
		apiFetch({
			path: '/pushpull/v1/sync/remotecommits',
		}).then((data) => {
			setRemoteCommits(data.reverse());
		}).catch((error) => {
			console.error(error);
		});
		apiFetch({
			path: '/pushpull/v1/sync/status',
		}).then((data) => {
			setStatus(data);
		}).catch((error) => {
			console.error(error);
		});
  };

	useEffect( () => {
    initializePane();
	}, [] );

  useEffect(() => {
    // unknown -> initial status
    // localempty -> local repo has no commits
    // remoteempty -> remote repo has no commits
    // synced -> both repos are at the same level
    // conflict -> repos have diverged
    // needpull -> remote repo has commits that local repo does not have
    // needpush -> local repo has commits that remote repo does not have
    // error -> error occurred
    if (status['status'] === 'synced') {
      createSuccessNotice(__('Both repositories are at the same level. There is no need to push or pull any commits.'), {
        isDismissible: true,
      });
    } else if (status['status'] === 'conflict') {
      createErrorNotice(__('Repositories have diverged. Please resolve the conflict.'), {
        isDismissible: true,
      });
    } else if (status['status'] === 'needpull') {
      createWarningNotice(__('Your local repository needs to pull commits from the remote repository.'), {
        isDismissible: true,
      });
    } else if (status['status'] === 'needpush') {
      createWarningNotice(__('Your local repository needs to push commits to the remote repository.'), {
        isDismissible: true,
      });
    } else if (status['status'] === 'error') {
      createErrorNotice(__('There was an error.'), {
        isDismissible: true,
      });
    } else if (status['status'] === 'localempty') {
    } else if (status['status'] === 'remoteempty') {
    } else if (status['status'] === 'unknown') {
    }
  }, [status]);

  return (
	<>
    <Grid2 container rowSpacing={0} columnSpacing={2}>
      <Grid2 size={3} display="flex" justifyContent="left">
        <h2>{ __( 'Local commits ('+localCommits.length+')', 'pushpull' ) }</h2>
      </Grid2>
      <Grid2 size={3} display="flex" justifyContent="right">
        <MUIButton
          variant="contained"
          onClick={onClickPush}
          color={'primary'}
          disabled={status['status'] === 'synced' || status['status'] === 'localempty' || status['status'] === 'conflict' || status['status'] === 'needpull'}
          endIcon={<ArrowCircleRightIcon />}
        >
          { __( 'Push local commits', 'pushpull' ) }
        </MUIButton>
      </Grid2>
      <Grid2 size={3} display="flex" justifyContent="left">
        <MUIButton
          variant="contained"
          onClick={onClickPull}
          color={'primary'}
          disabled={status['status'] === 'synced' || status['status'] === 'remoteempty' || status['status'] === 'conflict' || status['status'] === 'needpush'}
          startIcon={<ArrowCircleLeftIcon />}
        >
          { __( 'Pull remote commits', 'pushpull' ) }
        </MUIButton>
      </Grid2>
      <Grid2 size={3} display="flex" justifyContent="right">
        <h2>{ __( 'Remote commits ('+remoteCommits.length+')', 'pushpull' ) }</h2>
      </Grid2>
      <Grid2 size={6}>
        <p>Latest local commit ID: {status['localLatestCommitHash'] ? status['localLatestCommitHash'] : "N/A" }</p>
      </Grid2>
      <Grid2 size={6}>
        <p>Latest remote commit ID: {status['remoteLatestCommitHash'] ? status['remoteLatestCommitHash'] : "N/A" }</p>
      </Grid2>
      <Grid2 size={6}>
        <StyledDataGrid
          rows={localCommits}
          columns={localcolumns}
          getRowId={(row) => row.id + '#' + row.checksum}
    /*			filterModel={{
            items: [
              { field: 'status', operator: 'isAnyOf', value: statuses },
            ],
          }}*/
          initialState={{
            pagination: {
              paginationModel: {
                pageSize: 50,
              },
            },
          }}
          pageSizeOptions={[5, 25, 50, 100]}
          //checkboxSelection
          //disableRowSelectionOnClick
    //      onRowSelectionModelChange={onRowSelectionHandler}
          getRowClassName={(params) => `super-app-theme--${params.row.status}`}
        />
      </Grid2>
      <Grid2 size={6}>
        <StyledDataGrid
          rows={remoteCommits}
          columns={remotecolumns}
          getRowId={(row) => row.id + '#' + row.checksum}
    /*			filterModel={{
            items: [
              { field: 'status', operator: 'isAnyOf', value: statuses },
            ],
          }}*/
          initialState={{
            pagination: {
              paginationModel: {
                pageSize: 50,
              },
            },
          }}
          pageSizeOptions={[5, 25, 50, 100]}
          //checkboxSelection
          //disableRowSelectionOnClick
    //      onRowSelectionModelChange={onRowSelectionHandler}
          getRowClassName={(params) => `super-app-theme--${params.row.status}`}
        />
      </Grid2>
    </Grid2>
	</>);
}

export default SyncPane;
