import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { NoticeList } from '@wordpress/components';

const Notices = () => {
    const { removeNotice } = useDispatch( noticesStore );
    const notices = useSelect( ( select ) =>
        select( noticesStore ).getNotices()
    );

    if ( notices.length === 0 ) {
        return null;
    }

    notices.forEach( ( notice ) => {
        setTimeout(() => {
            removeNotice(notice.id);
        }, 5000);
    });

    return <NoticeList notices={ notices } onRemove={ removeNotice } />;
};

export default Notices;
