import React from 'react';
import ReactHtmlParser from 'react-html-parser';
import styles from '../index.module.scss';

export default class ReforwardDisplay extends React.Component {
  constructor(props) {
    super(props);
  }

  handleReforwardClick(item) {
    if (item.threadId) {
      this.props.dzqRouter.router.push(`/thread/${item.threadId}`);
    }
  }

  render() {
    const { renderData } = this.props;
    if (!renderData) return null;
    const { body } = renderData || {};

    return (body || []).map(
      (item) =>
        (item.threadDetail || item.postsDetail) && (
          <div
            className={styles.wrapper}
            key={item.postId || item.threadId}
            onClick={this.handleReforwardClick.bind(this, item)}>
            {item.threadDetail && (
              <div className={styles['wrapper-item']}>
                <img
                  className={styles['wrapper-item_left']}
                  src={item.threadDetail.user?.avatar}
                  title={item.threadDetail.user?.nickname}
                  alt={item.threadDetail.user?.nickname}
                />
                <div className={styles['wrapper-item_right']}>
                  <h3>
                    @{item.threadDetail.user?.nickname}:{' '}
                    {item.threadDetail.title}
                  </h3>
                  <p>{ReactHtmlParser(item.threadDetail.content.text)}</p>
                </div>
              </div>
            )}
            {item.postsDetail && (
              <div className={styles['wrapper-item']}>
                <img
                  className={styles['wrapper-item_left']}
                  src={item.postsDetail.user?.avatar}
                  title={item.postsDetail.user?.nickname}
                  alt={item.postsDetail.user?.nickname}
                />
                <div className={styles['wrapper-item_right']}>
                  <h3>
                    @{item.postsDetail.user?.nickname}: {item.postsDetail.title}
                  </h3>
                  <p>{ReactHtmlParser(item.postsDetail.summaryText)}</p>
                </div>
              </div>
            )}
          </div>
        )
    );
  }
}
