<?php
/**
 * 订单模型
 *
 *
 *
 *
 * @copyright  Copyright (c) 2019 MoJiKeJi Inc. (http://www.fashop.cn)
 * @license    http://www.fashop.cn
 * @link       http://www.fashop.cn
 * @since      File available since Release v1.1
 */

namespace App\Model;


class Order extends Model
{
	protected $softDelete = true;

	/**
	 * @param array  $condition
	 * @param string $condition_string
	 * @param string $fields
	 * @param array  $extend 追加返回那些表的信息,如array('order_extend','order_goods')
	 * @return array|bool
	 */
	public function getOrderInfo( $condition = [],  $fields = '*', $extend = [] )
	{
		$order_info = $this->field( $fields )->where( $condition )->find();
		if( empty( $order_info ) ){
			return [];
		}
		//追加返回订单扩展表信息
		if( in_array( 'order_extend', $extend ) ){
			$order_info['extend_order_extend'] = $this->getOrderExtendInfo( ['id' => $order_info['id']] );
		}

		//返回买家信息
		if( in_array( 'user', $extend ) ){
			$order_info['extend_user'] = \App\Model\User::init()->getUserInfo( ['id' => $order_info['user_id']] );
		}

		//追加返回商品信息
		if( in_array( 'order_goods', $extend ) ){
			//取商品列表
			$order_goods_list                 = $this->getOrderGoodsList( ['order_id' => $order_info['id']] );
			$order_info['extend_order_goods'] = [];
			foreach( $order_goods_list as $value ){
				// 退款平台处理状态 默认0处理中(未处理) 10拒绝(驳回) 20同意 30成功(已完成) 50取消(用户主动撤销) 51取消(用户主动收货)
				// 不可退款
				if( $value['refund_id'] > 0 && in_array( $value['refund_handle_state'], [20, 30, 51] ) ){
					$value['if_refund'] = false;
				} else{
					$value['if_refund'] = true;
				}
				// refund_state 0不显示申请退款按钮 1显示申请退款按钮 2显示退款中按钮 3显示退款完成
				if( $order_info['state'] <= 10 ){
					$refund_state = 0;
				} else{
					if( $value['lock_state'] == 0 && $value['refund_id'] == 0 ){
						$refund_state = 1;
					} else{
						if( $value['refund_handle_state'] == 30 ){
							$refund_state = 3;
						} else{
							$refund_state = 2;
						}
					}
				}

				$value['refund_state']              = $refund_state;
				$order_info['extend_order_goods'][] = $value;
			}
		}
		return $order_info;
	}

	/**
	 * @param array  $condition
	 * @param string $field
	 * @return array|bool
	 */
	public function getOrderExtendInfo( $condition = [] )
	{
		$info = \App\Model\OrderExtend::init()->where( $condition )->find();
		return $info;
	}

	/**
	 * @param array $condition
	 * @return array|bool
	 */
	public function getOrderPayInfo( $condition = [] )
	{
		$info = \App\Model\OrderPay::init()->where( $condition )->find();
		return $info;
	}


	/**
	 * @param array  $condition
	 * @param string $field
	 * @param string $order
	 * @param string $page
	 * @param array  $extend
	 * @return array
	 */
	public function getOrderList( $condition = [], $field = '*', $order = 'id desc', $page = [1, 20], $extend = [] )
	{
		$list = $this->where( $condition )->field( $field )->order( $order )->page( $page )->select();
		if( !$list ){
			return [];
		}

		$order_list = [];
		foreach( $list as $order ){
			$order['state_desc'] = self::orderState( $order );
			//1默认2拼团商品3限时折扣商品4组合套装5赠品
			if( $order['goods_type'] == 2 ){
				$order['group_state_desc'] = self::orderGroupState( $order );
			}
			$order['payment_name'] = self::orderPaymentName( $order['payment_code'] );
			if( !empty( $extend ) ){
				$order_list[$order['id']] = $order;
			}

		}
		if( empty( $order_list ) ){
			$order_list = $list;
		}

		// 追加返回订单扩展表信息
		if( in_array( 'order_extend', $extend ) ){
			$model = new OrderExtend;
			$order_extend_list = $model->getOrderExtendList( ['id' => ['in', array_keys( $order_list )]] );
			foreach( $order_extend_list as $value ){
				$order_list[$value['id']]['extend_order_extend'] = $value;
			}
		}

		// 追加返回买家信息
		if( in_array( 'user', $extend ) ){
			$user_id_array = [];
			foreach( $order_list as $value ){
				if( !in_array( $value['user_id'], $user_id_array ) ){
					$user_id_array[] = $value['user_id'];
				}
			}
			// todo 简化
			//->field('id') ->key
			$user_list = \App\Model\User::init()->where( ['id' => ['in', $user_id_array]] )->select();
			$user_list = $this->array_under_reset( $user_list, 'id' );
			foreach( $order_list as $order_id => $order ){
				$order_list[$order_id]['extend_user'] = $user_list[$order['user_id']];
			}
		}
		// 追加返回商品信息
		if( in_array( 'order_goods', $extend ) ){
			//取商品列表
			$order_goods_list = $this->getOrderGoodsList( ['order_id' => ['IN', array_keys( $order_list )]] );
			if(!empty($order_goods_list)){
				foreach( $order_goods_list as $value ){
					$order_list[$value['order_id']]['extend_order_goods'][] = $value;
				}
			}
		}
		// todo pay_info
		return array_values( $order_list );
	}

	/**
	 * 返回以原数组某个值为下标的新数据
	 *
	 * @param array  $array
	 * @param string $key
	 * @param int    $type 1一维数组2二维数组
	 * @return array
	 */
	private function array_under_reset( $array, $key, $type = 1 )
	{
		if( !$key ){
			return $array;
		}

		if( is_array( $array ) ){
			$tmp = [];
			foreach( $array as $v ){
				if( $type === 1 ){
					$tmp[$v[$key]] = $v;
				} elseif( $type === 2 ){
					$tmp[$v[$key]][] = $v;
				}
			}
			return $tmp;
		} else{
			return $array;
		}
	}

	/**
	 * 已取消订单数量
	 * @param array $condition
	 */
	public function getOrderStateCancelCount( $condition = [] )
	{
		$condition['no_display']   = 0;
		$condition['state']        = \App\Biz\Order::state_cancel;
		$condition['refund_state'] = ['=', 0];
		$condition['lock_state']   = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待付款订单数量
	 * @param array $condition
	 */
	public function getOrderStateNewCount( $condition = [] )
	{
		$condition['no_display']   = 0;
		$condition['state']        = \App\Biz\Order::state_new;
		$condition['refund_state'] = ['=', 0];
		$condition['lock_state']   = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待发货订单数量
	 * @param array $condition
	 */
	public function getOrderStatePayCount( $condition = [] )
	{
		$condition['no_display'] = 0;
		$condition['state']      = \App\Biz\Order::state_pay;
		// $condition['refund_state'] = array('=',0);
		// $condition['lock_state'] = array('=',0);
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待收货订单数量
	 * @param array $condition
	 */
	public function getOrderStateSendCount( $condition = [] )
	{
		$condition['no_display'] = 0;
		$condition['state']      = \App\Biz\Order::state_send;
		// $condition['refund_state'] = array('=',0);
		// $condition['lock_state'] = array('=',0);
		return $this->getOrderCount( $condition );
	}

	/**
	 * 已完成订单数量
	 * @param array $condition
	 */
	public function getOrderSuccessCount( $condition = [] )
	{
		$condition['no_display']    = 0;
		$condition['state']         = \App\Biz\Order::state_success;
		$condition['finnshed_time'] = ['>', time() - \App\Biz\Order::ORDER_EVALUATE_TIME];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待评价订单数量
	 * @param array $condition
	 */
	public function getOrderStateEvalCount( $condition = [] )
	{
		$condition['no_display']     = 0;
		$condition['state']          = \App\Biz\Order::state_success;
		$condition['evaluate_state'] = 0;
		$condition['finnshed_time']  = ['>', time() - \App\Biz\Order::ORDER_EVALUATE_TIME];
		// $condition['refund_state'] = array('=',0);
		// $condition['lock_state'] = array('=',0);
		return $this->getOrderCount( $condition );
	}

	/**
	 * 退款订单数量
	 * @param array $condition
	 */
	public function getOrderRefundCount( $condition = [] )
	{
		$condition['state']        = \App\Biz\Order::state_pay;
		$condition['refund_state'] = ['!=', 0]; // 退款状态:0是无退款,1是部分退款,2是全部退款
		$condition['lock_state']   = ['>', 0]; // 锁定状态:0是正常,大于0是锁定,默认是0
		return $this->getOrderCount( $condition );
	}

	/**
	 * 取得订单数量
	 * @param array $condition
	 */
	public function getOrderCount( $condition )
	{
		return $this->where( $condition )->count();
	}

	/**
	 * 取得订单总钱数
	 * @param array $condition
	 */
	public function getOrderSum( $condition )
	{
		return $this->where( $condition )->sum( 'amount' );
	}

	/**
	 * 取得订单商品表详细信息
	 * @param array  $condition
	 * @param string $fields
	 * @param string $order
	 * @return mixed
	 */
	public function getOrderGoodsInfo( $condition = [], $fields = '*', $order = 'id asc' )
	{
		$data = \App\Model\OrderGoods::init()->where( $condition )->field( $fields )->order( $order )->find();
		return $data;
	}


	/**
	 * 取得订单商品表列表
	 * @param array  $condition
	 * @param string $fields
	 * @param string $page
	 * @param string $order
	 * @param string $group
	 * @param string $key
	 */
	public function getOrderGoodsList( $condition = [], $fields = '*', $order = 'id desc', $page = [1, 1000], $key = null )
	{
		$list = \App\Model\OrderGoods::init()->field( $fields )->where( $condition )->order( $order )->page( $page )->select();
		return $this->array_under_reset( $list, $key );
	}

	/**
	 * 插入订单表信息
	 * @param array $data
	 * @return int 返回 insert_id
	 */
	public function addOrder( $data )
	{
		return $this->add( $data );
	}


	/**
	 * 更改订单信息
	 *
	 * @param array $data
	 * @param array $condition
	 */
	public function editOrder( $condition, $data )
	{
		return $this->where( $condition )->edit( $data );
	}


	/**
	 * 返回是否允许某些操作
	 * @param $operate
	 * @param $order_info
	 * @return bool
	 */
	public static function getOrderOperateState( $operate, $order_info )
	{
		if( !is_array( $order_info ) || empty( $order_info ) ){
			return false;
		}

		switch( $operate ){
			//买家支付
		case 'user_pay':
			$state = ($order_info['state'] === \App\Biz\Order::state_new) && (time() < $order_info['payable_time']);
		break;
			//买家取消订单
		case 'user_cancel':
			$state = ($order_info['state'] == \App\Biz\Order::state_new) || ($order_info['payment_code'] == 'offline' && $order_info['state'] == \App\Biz\Order::state_pay);
		break;

			//买家取消订单
		case 'refund_cancel':
			$state = $order_info['refund_state'] == 1 && !intval( $order_info['lock_state'] );
		break;

			//商家取消订单
		case 'cancel':
			$state = ($order_info['state'] == \App\Biz\Order::state_new) || ($order_info['payment_code'] == 'offline' && in_array( $order_info['state'], [
						\App\Biz\Order::state_pay,
						\App\Biz\Order::state_send,
					] ));
		break;

			//平台取消订单
		case 'system_cancel':
			$state = ($order_info['state'] == \App\Biz\Order::state_new) || ($order_info['payment_code'] == 'offline' && $order_info['state'] == \App\Biz\Order::state_pay);
		break;

			//平台收款
		case 'system_receive_pay':
			$state = $order_info['state'] == \App\Biz\Order::state_new && $order_info['payment_code'] == 'online';
		break;

		break;

			//调整运费
		case 'modify_price':
			$state = ($order_info['state'] == \App\Biz\Order::state_new) || ($order_info['payment_code'] == 'offline' && $order_info['state'] == \App\Biz\Order::state_pay);
			$state = floatval( $order_info['shipping_fee'] ) > 0 && $state;
		break;

			//发货
		case 'send':
			$state = !$order_info['lock_state'] && $order_info['state'] == \App\Biz\Order::state_pay;
		break;

			//收货
		case 'receive':
			$state = $order_info['state'] == \App\Biz\Order::state_send;
		break;

			//评价
		case 'evaluate':
			$state = !$order_info['lock_state'] && !intval( $order_info['evaluate_state'] ) && $order_info['state'] == \App\Biz\Order::state_success && time() - intval( $order_info['finnshed_time'] ) < \App\Biz\Order::ORDER_EVALUATE_TIME;
		break;
			// 子商品是否可评价
		case 'evaluate_goods':
			//			&& (time() - intval( $order_info['finnshed_time'] ) < \App\Logic\Order::ORDER_EVALUATE_TIME
			$state = $order_info['state'] === \App\Biz\Order::state_success;
		break;
			//锁定
		case 'lock':
			$state = intval( $order_info['lock_state'] ) ? true : false;
		break;

			//快递跟踪
		case 'deliver':
			$state = !empty( $order_info['tracking_no'] ) && in_array( $order_info['state'], [
					\App\Biz\Order::state_send,
					\App\Biz\Order::state_success,
				] );
		break;
			//分享
		case 'share':
			$state = $order_info['state'] == \App\Biz\Order::state_success;
		break;

		}
		return $state;

	}


	/**
	 * 买家订单状态操作
	 * @param $state_type
	 * @param $order_info
	 * @param $user_id
	 * @param $username
	 * @param $extend_msg
	 * @return bool
	 */
	public function userChangeState( $state_type, $order_info, $user_id, $username, $extend_msg ) : bool
	{
		try{
			$this->startTransaction();

			if( $state_type == 'order_cancel' ){
				$this->userChangeStateOrderCancel( $order_info, $user_id, $username, $extend_msg );
				$this->message = '成功取消了订单';
			} elseif( $state_type == 'order_receive' ){
				$this->userChangeStateOrderReceive( $order_info, $user_id, $username, $extend_msg );
				$this->message = '订单交易成功,您可以评价本次交易';
			}
			$this->commit();
			return true;

		} catch( \Exception $e ){
			$this->rollback();
			return false;
		}

	}

	/**
	 * 取消订单操作
	 * @param    array  $order_info 订单信息
	 * @param    int    $user_id
	 * @param    string $username
	 * @param    string $extend_msg
	 * @return   \Exception | bool
	 */
	private function userChangeStateOrderCancel( $order_info, $user_id, $username, $extend_msg ) : bool
	{
		$order_id = $order_info['id'];
		$if_allow = $this->getOrderOperateState( 'user_cancel', $order_info );
		if( !$if_allow ){
			throw new \Exception( '非法访问' );
		}

		$goods_list = $this->getOrderGoodsList( ['order_id' => $order_id] );

		if( is_array( $goods_list ) && !empty( $goods_list ) ){

			$data        = [];
			$common_data = [];
			foreach( $goods_list as $goods ){

				$data['stock']    = ['exp', 'stock+'.$goods['goods_num']];
				$data['sale_num'] = ['exp', 'sale_num-'.$goods['goods_num']];
				\App\Model\Goods::init()->editGoods( ['id' => $goods['goods_id']], $data );

				// 主表更新
				$common_data['stock']    = ['exp', 'stock+'.$goods['goods_num']];
				$common_data['sale_num'] = ['exp', 'sale_num-'.$goods['goods_num']];
				$update                  = \App\Model\GoodsSku::init()->editGoodsSku( ['id' => $goods['goods_common_id']], $common_data );
				if( !$update ){
					throw new \Exception( '保存失败' );
				}
			}
		}
		// 解冻预存款
		$pd_amount = floatval( $order_info['pd_amount'] );
		if( $pd_amount > 0 ){
			$pd_data             = [];
			$pd_data['user_id']  = $user_id;
			$pd_data['username'] = $username;
			$pd_data['amount']   = $pd_amount;
			$pd_data['order_sn'] = $order_info['sn'];
			\App\Model\PdRecharge::init()->changePd( 'order_cancel', $pd_data );
		}
		//更新订单信息
		$update_order = ['state' => \App\Biz\Order::state_cancel, 'pd_amount' => 0];
		$update       = $this->editOrder( ['id' => $order_id], $update_order );
		if( !$update ){
			throw new \Exception( '保存失败' );
		}

		//添加订单日志
		$data             = [];
		$data['order_id'] = $order_id;
		$data['role']     = 'buyer';
		$data['msg']      = '取消了订单';
		if( $extend_msg ){
			$data['msg'] .= ' ( '.$extend_msg.' )';
		}
		$data['order_state'] = \App\Biz\Order::state_cancel;
		return OrderLog::init()->addOrderLog( $data );
	}

	/**
	 * 收货操作
	 * @param array $order_info
	 */
	private function userChangeStateOrderReceive( $order_info, $user_id, $username, $extend_msg )
	{
		$order_id = $order_info['id'];
		$if_allow = $this->getOrderOperateState( 'receive', $order_info );
		if( !$if_allow ){
			throw new \Exception( '非法访问' );
		}

		//更新订单状态
		$update_order['finnshed_time'] = time();
		$update_order['state']         = \App\Biz\Order::state_success;
		$update                        = $this->editOrder( ['id' => $order_id], $update_order );
		if( !$update ){
			throw new \Exception( '订单修改失败' );
		}

		//添加订单日志
		$data             = [];
		$data['order_id'] = $order_id;
		$data['role']     = 'buyer';
		$data['msg']      = '签收了货物';
		if( $extend_msg ){
			$data['msg'] .= ' ( '.$extend_msg.' )';
		}
		$data['order_state'] = \App\Biz\Order::state_success;
		$order_log_id        = OrderLog::init()->addOrderLog( $data );
		if( !$order_log_id ){
			throw new \Exception( '日志保存失败' );
		}


		$refund = \App\Model\OrderRefund::init()->getOrderRefundInfo( ['order_id' => $order_id, 'is_close' => 0] );

		//查询收货订单是否存在退款记录 存在则关闭
		if( $refund ){
			$refund_res = \App\Model\OrderRefund::init()->editOrderRefund( ['order_id' => $order_id], [
				'handle_time'    => time(),
				'handle_message' => '因您确认收货，本次申请关闭',
				'is_close'       => 1,   //此退款关闭
				'order_lock'     => 1,   //锁定类型:1为不用锁定,2为需要锁定
				'handle_state'   => 51,  //平台处理状态 默认0处理中(未处理) 10拒绝(驳回) 20同意 30成功(已完成) 50取消(用户主动撤销) 51取消(用户主动收货)
			] );
			if( !$refund_res ){
				throw new \Exception( '退款/退货记录修改失败' );
			}

			// 更改订单状态 解锁 子订单解锁
			$order_goods_res = \App\Model\OrderGoods::init()->editOrderGoods( [
				'lock_state' => 1,
				'order_id'   => $order_id,
			], [
				'lock_state'          => 0,
				'refund_handle_state' => 51,
				'refund_id'           => 0,
			] );

			if( !$order_goods_res ){
				throw new \Exception( '订单修改失败' );
			}

			$order_res = $this->editOrder( [
				'id'         => $order_id,
				'lock_state' => ['>=', 1],
			], [
				'refund_state' => 0,//退款状态:0是无退款,1是部分退款,2是全部退款
				'lock_state'   => 0,
				'delay_time'   => time(),
			] );
			if( !$order_res ){
				throw new \Exception( '订单修改失败' );
			}
		}

	}

	/**
	 * 获得任意字段
	 * @param array $condition
	 * @param array $update_data
	 */
	public function getOrderField( $condition , $field )
	{
		return $this->where( $condition )->value( $field );
	}

	/**
	 * 获得任意字段
	 * @param array $condition
	 * @param array $update_data
	 */
	public function getOrderValue( $condition , $field )
	{
		return $this->where( $condition )->value( $field );
	}

	/**
	 * 获得任意字列
	 * @param array $condition
	 * @param array $update_data
	 */
	public function getOrderColumn( $condition , $field )
	{
		return $this->where( $condition )->column( $field );
	}

	/**
	 * 已支付但未发货的订单相关数据
	 * @param  array $condition
	 * @return
	 */
	public function isPayNoSend( $condition = [] )
	{
		//查询改用户已支付未发货的订单的地址id
		$data = $this->where( $condition )->join( 'order_extend', 'order.id = order_extend.id', 'LEFT' )->field( 'order.id as order_id,order.address_id,address_area_id,address_street_id,order_extend.reciver_info' )->select();
		if( $data ){
			foreach( $data as $key => $value ){
				$data[$key]['reciver_info'] = unserialize( $value['reciver_info'] );
			}
		} else{
			$data = [];
		}
		return $data;
	}


	/**
	 * 已取消订单数量
	 * @param array $condition
	 */
	public function getAdminOrderStateCancelCount( $condition = [] )
	{
		$condition['state']        = \App\Biz\Order::state_cancel;
		$condition['refund_state'] = ['=', 0];
		$condition['lock_state']   = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待付款订单数量
	 * @param array $condition
	 */
	public function getAdminOrderStateNewCount( $condition = [] )
	{
		$condition['state']        = \App\Biz\Order::state_new;
		$condition['refund_state'] = ['=', 0];
		$condition['lock_state']   = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待发货订单数量
	 * @param array $condition
	 */
	public function getAdminOrderStatePayCount( $condition = [] )
	{
		$condition['state']        = \App\Biz\Order::state_pay;
		$condition['refund_state'] = ['=', 0];
		$condition['lock_state']   = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待收货订单数量
	 * @param array $condition
	 */
	public function getAdminOrderStateSendCount( $condition = [] )
	{

		$condition['state']        = \App\Biz\Order::state_send;
		$condition['refund_state'] = ['=', 0];
		$condition['lock_state']   = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 已完成订单数量
	 * @param array $condition
	 */
	public function getAdminOrderSuccessCount( $condition = [] )
	{

		$condition['state']        = \App\Biz\Order::state_success;
		$condition['refund_state'] = 0;
		return $this->getOrderCount( $condition );
	}

	/**
	 * 待评价订单数量
	 * @param array $condition
	 */
	public function getAdminOrderStateEvalCount( $condition = [] )
	{
		$condition['state']          = \App\Biz\Order::state_success;
		$condition['evaluate_state'] = 0;
		$condition['finnshed_time']  = ['>', time() - \App\Biz\Order::ORDER_EVALUATE_TIME];
		$condition['refund_state']   = ['=', 0];
		$condition['lock_state']     = ['=', 0];
		return $this->getOrderCount( $condition );
	}

	/**
	 * 退款订单数量
	 * @param array $condition
	 */
	public function getAdminOrderRefundCount( $condition = [] )
	{
		$condition['refund_state'] = ['!=', 0]; // 退款状态:0是无退款,1是部分退款,2是全部退款
		$condition['lock_state']   = ['>', 0]; // 锁定状态:0是正常,大于0是锁定,默认是0
		return $this->getOrderCount( $condition );
	}
	// Admin使用

	/**
	 * 获得订单信息
	 * @param   $condition
	 * @param   $field
	 * @return
	 */
	public function getOneOrderInfo( $condition = [], $field = '*' )
	{
		$data = $this->where( $condition )->field( $field )->find();
		return $data;
	}


	/**
	 * 取得订单状态文字输出形式
	 *
	 * @param array $order_info 订单数组
	 * @return string $order_state 描述输出
	 */
	static function orderState( $order_info )
	{
		switch( $order_info['state'] ){
		case \App\Biz\Order::state_cancel:
			$order_state = '已取消';
		break;
		case \App\Biz\Order::state_new:
			$order_state = '待付款';
		break;
		case \App\Biz\Order::state_pay:
			$order_state = '待发货';
		break;
		case \App\Biz\Order::state_send:
			$order_state = '待收货';
		break;
		case \App\Biz\Order::state_success:
			$order_state = '交易完成';
		break;
		}
		return $order_state;
	}

	/**
	 * 取得订单拼团状态文字输出形式
	 * @param array $order_info 订单数组
	 * @return string $order_group_state 描述输出
	 */
	static function orderGroupState( $order_info )
	{
		switch( $order_info['group_state'] ){
		case \App\Biz\Order::group_state_new:
			$order_group_state = '待付款';
		break;
		case \App\Biz\Order::group_state_pay:
			$order_group_state = '待开团';
		break;
		case \App\Biz\Order::group_state_success:
			$order_group_state = '拼团成功';
		break;
		case \App\Biz\Order::group_state_fail:
			$order_group_state = '拼团失败';
		break;
		}
		return $order_group_state;
	}

	/**
	 * 取得订单支付类型文字输出形式
	 *
	 * @param array $payment_code
	 * @return string
	 */
	static function orderPaymentName( $payment_code )
	{
		return str_replace( ['offline', 'online', 'alipay', 'tenpay', 'chinabank', 'predeposit'], ['货到付款', '在线付款', '支付宝', '财付通', '网银在线', '预存款'], $payment_code );
	}

	/**
	 * 列表
	 * @param   $condition
	 * @param   $condition_str
	 * @param   $field
	 * @param   $order
	 * @param   $page
	 * @param   $group
	 * @return
	 */
	public function getOrderCommonList( $condition = [], $condition_str = '', $field = '*', $order = 'id desc', $page = [1, 20], $group = '' )
	{
		if( $page == '' ){
			$data = $this->where( $condition )->where( $condition_str )->order( $order )->field( $field )->group( $group )->select();

		} else{
			$data = $this->where( $condition )->where( $condition_str )->order( $order )->field( $field )->page( $page )->group( $group )->select();
		}
		return $data;
	}

	/**
	 * 获得数量
	 * @param   $condition
	 * @param   $condition_str
	 * @param   $distinct [去重]
	 * @return
	 */
	public function getOrderCommonCount( $condition = [], $condition_str = '', $distinct = '' )
	{
		if( $distinct == '' ){
			return $this->where( $condition )->where( $condition_str )->count();

		} else{
			return $this->where( $condition )->where( $condition_str )->count( "DISTINCT ".$distinct );

		}
	}

	/**
	 * 获得信息
	 * @param   $condition
	 * @param   $condition_str
	 * @param   $field
	 * @return
	 */
	public function getOrderCommonInfo( $condition = [], $condition_str = '', $field = '*' )
	{
		$data = $this->where( $condition )->where( $condition_str )->field( $field )->find();
		return $data;
	}

	/**
	 * 分佣 推广效果状态描述
	 * @param   $update
	 */
	public function distributionPromotionDesc( $data )
	{

		foreach( $data as $key => $value ){
			$amount      = ($value['revise_amount'] > 0) ? $value['revise_amount'] : $value['amount'];
			$freight_fee = ($value['revise_freight_fee'] > 0) ? $value['revise_freight_fee'] : $value['freight_fee'];

			if( $value['pay_name'] != 'online' ){
				$data[$key]['distribution_state'] = 1;  //货到付款不参与结算
				continue;
			}

			if( $value['state'] == 0 ){
				$data[$key]['distribution_state'] = 2;  //订单关闭  [不显示佣金比例和佣金]
				continue;
			}
			if( $value['state'] == 10 ){
				$data[$key]['distribution_state'] = 3;  //待付款   [不显示佣金比例和佣金]
				continue;
			}

			if( floatval( array_sum( array_column( $value['extend_order_goods'], 'distribution_ratio' ) ) ) == 0 ){
				$data[$key]['distribution_state'] = 4;  //无佣金 [原因是 商品不参与推广] [不显示佣金比例和佣金]
				continue;
			}

			if( $value['state'] >= 20 ){
				if( $value['refund_amount'] > 0 ){
					if( ($amount - $freight_fee - $value['refund_amount']) > 0 ){
						if( distribution_settlement == 0 ){
							$data[$key]['distribution_state'] = 5;  //待结算,部分退款

						} else{
							$data[$key]['distribution_state'] = 6;  //已结算,部分退款
						}
					} else{
						$data[$key]['distribution_state'] = 7;  //不结算,全额退款
					}

				} else{
					if( distribution_settlement == 0 ){
						$data[$key]['distribution_state'] = 8;  //待结算

					} else{
						$data[$key]['distribution_state'] = 9;  //已结算
					}
				}
			}
		}
		return $data;
	}

}

?>