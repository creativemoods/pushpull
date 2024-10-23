import * as React from 'react';
import Chip from '@mui/material/Chip';
import { styled } from '@mui/material/styles';
import DifferenceIcon from '@mui/icons-material/Difference';
import CloudDownloadIcon from '@mui/icons-material/CloudDownload';
import BackupIcon from '@mui/icons-material/Backup';
import DoneIcon from '@mui/icons-material/Done';

const StyledChip = styled(Chip)(({ theme }) => ({
  justifyContent: 'left',
  '& .icon': {
    color: 'inherit',
  },
  '&.Open': {
    color: (theme.vars || theme).palette.info.dark,
    border: `1px solid ${(theme.vars || theme).palette.info.main}`,
  },
  '&.identical': {
    color: (theme.vars || theme).palette.success.dark,
    border: `1px solid ${(theme.vars || theme).palette.success.main}`,
  },
  '&.PartiallyFilled': {
    color: (theme.vars || theme).palette.warning.dark,
    border: `1px solid ${(theme.vars || theme).palette.warning.main}`,
  },
  '&.Rejected': {
    color: (theme.vars || theme).palette.error.dark,
    border: `1px solid ${(theme.vars || theme).palette.error.main}`,
  },
}));

const Status = React.memo((props) => {
  const { status } = props;

  let icon = null;
  if (status === 'notremote') {
    icon = <BackupIcon className="icon" />;
  } else if (status === 'notlocal') {
    icon = <CloudDownloadIcon className="icon" />;
  } else if (status === 'different') {
    icon = <DifferenceIcon className="icon" />;
  } else if (status === 'identical') {
    icon = <DoneIcon className="icon" />;
  }

  let label = status;
  if (status === 'notlocal') {
    label = 'Only remote';
  }
  if (status === 'notremote') {
    label = 'Only local';
  }
  if (status === 'different') {
    label = 'Different';
  }
  if (status === 'identical') {
    label = 'Identical';
  }

  return (
    <StyledChip
      className={status}
      icon={icon}
      size="small"
      label={label}
      variant="outlined"
    />
  );
});

export function renderStatus(params) {
  if (params.value == null) {
    return '';
  }

  return <Status status={params.value} />;
}
