<?php
if (!defined('ROOT_PATH'))
{
	if (is_file('../../../shop/configs/config.ini.php'))
	{
		require_once '../../../shop/configs/config.ini.php';
	}
	else
	{
		die('请先运行index.php,生成应用程序框架结构！');
	}
}

$file_name_row = pathinfo(__FILE__);
$crontab_file = $file_name_row['basename'];

$Shop_BaseModel     = new Shop_BaseModel();
//新自动收货功能



$last  = date('Y-m-d', strtotime('-10 day'));
$sql = "select order_id from yf_order_deliver_goods where order_id in ( SELECT order_id FROM yf_order_base where shop_id =2  ) and status  = 4  AND deliver_goods_date <= '".$last."' and deliver_goods_date <> '0000-00-00' GROUP BY order_id  ";
$data = $Shop_BaseModel->sql->getAll($sql);
confirmOrder($data);
echo "\r\n yf_order_goods \r\n";
$sql = "select order_id from yf_order_goods where order_id in ( SELECT order_id FROM yf_order_base where shop_id = 2 and order_status = 6 order by order_date ) and order_goods_status <> 6 and   order_goods_num > 0 GROUP BY order_id   ";
$data = $Shop_BaseModel->sql->getAll($sql);
confirmOrder($data);

echo "\r\n yf_order_deliver_goods \r\n";

$sql = "select order_id from yf_order_goods where order_goods_id in ( select order_goods_id from yf_order_deliver_goods where order_id in  (SELECT order_id FROM yf_order_base where shop_id = 2 and  order_create_time > '2023-01-01 00:00:00' order by order_date)  and status = 6 and deliver_goods_num > 0 and deliver_goods_date <= '".$last."' group by order_goods_id ) and  order_goods_num > 0 and order_goods_status <> 6 group by order_id   ";
$data = $Shop_BaseModel->sql->getAll($sql);

confirmOrder($data);

//补充逻辑（判断yf_order_deliver_goods 中deliver_goods_date == 0000-00-00 的且status =6 的删除（删除前判断是否是备货中 误操作）
$sql ="select * from yf_order_deliver_goods where order_id in (SELECT order_id FROM yf_order_base where shop_id =2) and deliver_goods_date = '0000-00-00' and status <> 3 order by id DESC" ;

$data = $Shop_BaseModel->sql->getAll($sql);
foreach($data as $v){
    $num_sql = "SELECT count(id) as count FROM yf_order_serial_number where order_id = '".$v["order_id"]."' AND order_number = '".$v["order_number"]."' and common_id = (select  common_id from yf_order_goods where order_goods_id = ".$v["order_goods_id"].") ";
    $num_data = $Shop_BaseModel->sql->getRow($num_sql);
    if(empty($num_data) || $num_data["count"] == 0){
         $Shop_BaseModel->sql->exec("delete FROM  yf_order_deliver_goods where id = ".$v["id"]." and deliver_goods_date = '0000-00-00' and status <> 3 ");
        Yf_Log::log("confirmOrder:delete:yf_order_deliver_goods: ". json_encode($v), Yf_Log::INFO, 'confirmOrder'); 
    }else{
        $get_Sql = "select * from yf_order_deliver_goods where order_number = '".$v["order_number"]."' and order_goods_id = '".$v["order_goods_id"]."' and   order_id = '".$v["order_id"]."' and   deliver_goods_num = '".$v["deliver_goods_num"]."' and id <> '".$v["id"]."'";
        $get_data = $Shop_BaseModel->sql->getRow($get_Sql);
        if( !empty($get_data)){
            $Shop_BaseModel->sql->exec("delete FROM  yf_order_deliver_goods where id = ".$v["id"]." and deliver_goods_date = '0000-00-00' and status <> 3 ");
            Yf_Log::log("confirmOrder:delete:yf_order_deliver_goods: ". json_encode($v), Yf_Log::INFO, 'confirmOrder'); 
        }else{
           $Shop_BaseModel->sql->exec("UPDATE  yf_order_deliver_goods SET deliver_goods_date = '".date("'Y-m-d",strtotime($v[" created_at"]))."'  where id = ".$v["id"]);
           Yf_Log::log("confirmOrder:UPDATE:yf_order_deliver_goods: ". json_encode($v), Yf_Log::INFO, 'confirmOrder'); 
        }
       
    }
}

$sql = "select order_id,order_goods_id,order_goods_num FROM yf_order_goods where order_goods_num>0 and order_goods_num = deliver_goods_num and order_id in (select order_id from yf_order_base where shop_id = 2 and  order_create_time >'2023-01-01 00:00:00') and order_goods_status =6 ";
$data = $Shop_BaseModel->sql->getAll($sql);
foreach($data as $v){
    $get_num_sql = "select sum(deliver_goods_num) as num from yf_order_deliver_goods where order_goods_id = '".$v["order_goods_id"]."' and order_id = '".$v["order_id"]."' and status in (4,6) "; 
     $get_num_data = $Shop_BaseModel->sql->getRow($get_num_sql);
     if($get_num_data["num"] < $v["order_goods_num"]){
          Yf_Log::log("confirmOrder  - ".$v["order_id"], Yf_Log::INFO, 'update_confirmOrder');
     }
}

die("已完成");



function confirmOrder($data){
   
    if(empty($data)){return true;}
    $Shop_BaseModel     = new Shop_BaseModel();
    $last  = date('Y-m-d', strtotime('-10 day'));
    foreach($data as $v){
        Yf_Log::log("confirmOrder  - ".$v["order_id"], Yf_Log::INFO, 'confirmOrder');
        // 1. 查询该订单下有没有10天之前的收货订单
        $ids_sql = "select * from  yf_order_deliver_goods where order_id = '".$v['order_id']."' AND deliver_goods_date <= '". $last. "' group by order_number"; 
        $ids_data = $Shop_BaseModel->sql->getAll($ids_sql);
        if(empty($ids_data)){continue;}
        // 2.获取订单的order_base数据，并且跳过没有库位的数据
        $order_info = $Shop_BaseModel->sql->getRow("select * from yf_order_base where order_id = '".$v['order_id']."'");
         $judge = true;
        if(!$order_info["kaddress_id"] > 0 ){
            $address_sql="SELECT * FROM yf_shopstocksaddress where  spid = (select shop_id from yf_shop_base where user_id = '".$order_info["buyer_user_id"]."') ";
            $address_data = $Shop_BaseModel->sql->getRow($address_sql);
            if($address_data){
                 $judge = true;
            }else{
                $judge = false;
            }
        }
        //3.根据该订单查询出发货数据（发货与已完成）
        $tmp_sql = "select * from yf_order_deliver_goods  where order_id = '".$v['order_id']."' AND deliver_goods_date <= '". $last. "'  and status = 4 ";
        $tmp_data =  $Shop_BaseModel->sql->getAll($tmp_sql);
        foreach($tmp_data as $vo){
            //查看数据是否已经入库
            $get_serial_number_sql = " SELECT count(id) as num FROM yf_shopstocksps where nokey in( SELECT  xlh FROM yf_order_serial_number  where   order_id = '".$v["order_id"]."' and  mpino = (SELECT mpino FROM yf_goods_common where common_id = (SELECT common_id FROM yf_order_goods where order_goods_id =  '".$vo["order_goods_id"]."' )   ) and order_number ='".$vo["order_number"]."' ) and status = 0 and isdv = 0 "; 
            $get_serial_number_data =  $Shop_BaseModel->sql->getRow($get_serial_number_sql);
            
            if($get_serial_number_data != false && !empty($get_serial_number_data) && $get_serial_number_data["num"] >= $vo["deliver_goods_num"]){
              //全部在库，且数目一致
                if($vo["status"] != 6){
                    $update_sql = "UPDATE yf_order_deliver_goods SET  status  = 6  WHERE id = '".$vo['id']."' "; 
                    Yf_Log::log("confirmOrder:update->yf_order_deliver_goods->status: ". $vo["status"].":->update-> 6 where id :".$vo["id"], Yf_Log::INFO, 'confirmOrder');
                    $update_data = $Shop_BaseModel->sql->exec($update_sql);
                    
                }
            }else{
                if(!$judge){continue;}
                //有差别的调用接口确认收货
                $update_sql = "UPDATE yf_order_base SET  order_status = 4  WHERE order_id = '".$v['order_id']."'  "; 
                $update_data = $Shop_BaseModel->sql->exec($update_sql);
            
                $params = ["logisticNo"=>$vo["order_number"],"orderNo"=>$order_info["sap_order_id"]];
                $return_data = confirm_order($params);
                
                Yf_Log::log("confirmOrder  : confirm_order:data: ". json_encode($params)." : return :".json_encode( $return_data), Yf_Log::INFO, 'confirmOrder');
            

            }
        }

        //4. 判断对应的order_goods表是否需要更新
        $goods_sql = "select * from yf_order_goods where order_id = '".$v["order_id"]."'";
        $goods_data = $Shop_BaseModel->sql->getAll($goods_sql);
        
        foreach($goods_data as $vo){
            $tmp_sql = "select sum(a.deliver_goods_num) as sum_num from (
select deliver_goods_num from yf_order_deliver_goods where order_id = '".$v['order_id']."' AND status = 6 and order_goods_id = '".$vo["order_goods_id"]."' group by order_number ) as a";
            $tmp_data =  $Shop_BaseModel->sql->getRow($tmp_sql); 
            if( $tmp_data != false && $tmp_data["sum_num"]  >= $vo["order_goods_num"]){
                //更新order_goods_status ?= 6 
                $update_sql = "UPDATE yf_order_goods SET  order_goods_status = 6  WHERE order_goods_id = '".$vo['order_goods_id']."' "; 
                Yf_Log::log("confirmOrder:update->yf_order_goods->status: ". $vo["order_goods_status"].":->update-> 6 where id :".$vo["order_goods_id"], Yf_Log::INFO, 'confirmOrder');
                $update_data = $Shop_BaseModel->sql->exec($update_sql);
            }else{
               
                if($vo["order_goods_status"] == 6){
                  
                    $tmp_get_sql = "select * from yf_order_deliver_goods where order_id = '".$v["order_id"]."' and  order_goods_id = '".$vo["order_goods_id"]."' and status in (3,4) ";
                    $tmp_get_data = $Shop_BaseModel->sql->getAll($tmp_get_sql);
                    if(!empty($tmp_get_data)){
                        $update_sql = "UPDATE yf_order_goods SET  order_goods_status = 4  WHERE order_goods_id = '".$vo['order_goods_id']."' "; 
                        Yf_Log::log("confirmOrder:update->yf_order_goods->status: ". $vo["order_goods_status"].":->update-> 4 where id :".$vo["order_goods_id"], Yf_Log::INFO, 'confirmOrder');
                        $update_data = $Shop_BaseModel->sql->exec($update_sql);
                    }else{
                        $update_sql = "UPDATE yf_order_goods SET  order_goods_status = 2  WHERE order_goods_id = '".$vo['order_goods_id']."' "; 
                        Yf_Log::log("confirmOrder:update->yf_order_goods->status: ". $vo["order_goods_status"].":->update-> 2 where id :".$vo["order_goods_id"], Yf_Log::INFO, 'confirmOrder');
                        $update_data = $Shop_BaseModel->sql->exec($update_sql);
                    }
                }
            }

        }


        //5. 判断对应的order_goods表是否需要更新
        $order_sql = "select * from yf_order_goods where order_id = '".$v["order_id"]."' and order_goods_status <> 6";
        $order_data = $Shop_BaseModel->sql->getRow($order_sql);
        if(!$order_data){
            $update_sql = "UPDATE yf_order_base SET  order_status = 6  WHERE order_id = '".$v['order_id']."' "; 
            Yf_Log::log("confirmOrder:update->yf_order_base->order_status: ". $order_info["order_status"].":->update-> 6 where order_id :".$v['order_id'], Yf_Log::INFO, 'confirmOrder');
            $update_data = $Shop_BaseModel->sql->exec($update_sql);
        }else{
            $order_sql2 = "select * from yf_order_goods where order_id = '".$v["order_id"]."' and order_goods_status in (4,6)";
            $order_data2 = $Shop_BaseModel->sql->getRow($order_sql2);
            if($order_data2 !==false && !empty($order_data2)){
                $update_sql = "UPDATE yf_order_base SET  order_status = 4  WHERE order_id = '".$v['order_id']."' "; 
                Yf_Log::log("confirmOrder:update->yf_order_base->order_status: ". $order_info["order_status"].":->update-> 4 where order_id :".$v['order_id'], Yf_Log::INFO, 'confirmOrder');
                $update_data = $Shop_BaseModel->sql->exec($update_sql);
                 Yf_Log::log("orderid :".$v["order_id"]." 更新结束 ", Yf_Log::INFO, 'confirmOrder');
                continue;
            }
            $order_sql2 = "select * from yf_order_goods where order_id = '".$v["order_id"]."' and order_goods_status  = 3 ";
            $order_data2 = $Shop_BaseModel->sql->getRow($order_sql2);
            if($order_data2 !==false && !empty($order_data2)){
                $update_sql = "UPDATE yf_order_base SET  order_status = 4  WHERE order_id = '".$v['order_id']."' "; 
                Yf_Log::log("confirmOrder:update->yf_order_base->order_status: ". $order_info["order_status"].":->update-> 4 where order_id :".$v['order_id'], Yf_Log::INFO, 'confirmOrder');
                $update_data = $Shop_BaseModel->sql->exec($update_sql);
                 Yf_Log::log("orderid :".$v["order_id"]." 更新结束 ", Yf_Log::INFO, 'confirmOrder');
                continue;
            }

            $update_sql = "UPDATE yf_order_base SET  order_status =2 WHERE order_id = '".$v['order_id']."' "; 
            Yf_Log::log("confirmOrder:update->yf_order_base->order_status: ". $order_info["order_status"].":->update-> 2 where order_id :".$v['order_id'], Yf_Log::INFO, 'confirmOrder');
            $update_data = $Shop_BaseModel->sql->exec($update_sql);
        }

            
      Yf_Log::log("orderid :".$v["order_id"]." 更新结束 ", Yf_Log::INFO, 'confirmOrder');
        sleep(1);
    }
    
   
}

function request_post($url,$data){
    if (empty($url) || empty($data)) {
        return false;
    }
    $postUrl = $url;
    $curlPost = $data;
    $ch = curl_init();//初始化curl
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,0);
    curl_setopt($ch, CURLOPT_URL,$postUrl);
    curl_setopt($ch, CURLOPT_URL,$postUrl);//抓取指定网页
    curl_setopt($ch, CURLOPT_HEADER, 0);//设置header
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//要求结果为字符串且输出到屏幕上
    curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
    curl_setopt($ch, CURLOPT_POSTFIELDS, $curlPost);
    $data = curl_exec($ch);//运行curl
    curl_close($ch);
    return $data;
}

function confirm_order($data){
    $url = Yf_Registry::get('shop_api_url')."swpservice/index.php/v1/Action/Orders/logisticsFinish";
    //$url = Yf_Registry::get('shop_api_url')."swpservice/index.php/v1/Action/Orders/autoLogisticsFinish";
    //$data  = base64_encode(json_encode($data));
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST,FALSE);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($curl);
    curl_close($curl);
    return json_decode($output,true);
}



?>