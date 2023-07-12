<?php
declare (strict_types = 1);

namespace app;

use app\BaseController;
use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\facade\Config;
use think\facade\Db;

//excel

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * 控制器基础类
 */
class BaseReportController extends BaseController
{

    protected $dbname = '';
    protected $exHeaderField = [];
    protected $daole = 1;
    protected $suid = 0;
    protected $nshop_idv = 0;
    protected $myallsuids = [];
    // 初始化
    protected function initialize()
    {
        parent::initialize();
        //授权判断 是否直接报错
        if($this->request->login != 1){

            echo json_encode(['code' => 0,
                    'msg' => '请求未授权']
            );exit;
        }

        $this->dbname = Config::get('database.database')??Config::get('database.connections.mysql.database');

        $cid = $_COOKIE['id'];
        $n = Db::table('yf_seller_base')->where('user_id',$cid)->value('shop_id');
        $n2 = Db::table('lncrm_cms_diyform_dailishang')->where('user_id',$cid)->value('master');
        if($n2>0) $n = $n2;
        $this->nshop_idv = $n;
        $this->suid = Db::table('yf_shop_base')->where('shop_id',$n)->value('user_id');

        $this->myallsuids = Db::table('lncrm_cms_diyform_dailishang')->where('master',$this->nshop_idv)->column('user_id');
        $this->myallsuids[] = $this->suid;
    }

    protected  function RSuccess($data = array(),$msg = '成功',$more=[]){
        return json()->data(
            [
                'code' => 1,
                'msg' => $msg,
                'data' => $data,
                'append'=>$more
            ]
        );
    }
    protected  function RFail($data = array(),$msg = '失败'){
        return json()->data(
            [
                'code' => 0,
                'msg' => $msg,
                'data' => $data
            ]
        );
    }
    //执行报表导出
    protected function gotoexport($list,$changearr = []){
        if(input('useexport',0)==1){
            $tablefields  =input('headerTable','');
            $tablefields_en  = input('headerTable_en','');
            if(!empty($list) && is_object($list)) $list = $list->toArray();
            $tablefields_header = [];
            $tablefields_name = [];
            if(!empty($tablefields_en)){
                @$tablefields_en = base64_decode($tablefields_en);
                $kls = explode(',',$tablefields_en);
                $khv = [];
                foreach($this->exHeaderField as $v)$khv[$v[1]] = $v[0];
                foreach($kls as $t){
                    if(!empty($t)){
                        $tablefields_header[] = $t;
                        $na = $t;
                        $tablefields_name[] = !empty($khv[$t])?$khv[$t]:$na;
                    }
                }
            }else{
                $tablefields = urldecode(base64_decode($tablefields));
                $tablefls = explode(',',$tablefields);
                $tablefields_header = [];
                $tablefields_name = [];

                foreach($tablefls as $r){

                    if(empty($r)) continue;
                    $hk = explode('||',$r);
                    if(empty($hk[0]) || empty($hk[1]) ){
                        return $this->RFail($tablefields,'导出头部异常');
                        exit;
                    }
                    @$tablefields_name[] = $hk[0];
                    @$tablefields_header[] = $hk[1];
                }
            }

            $this->RExoprt($list,$tablefields_header,$tablefields_name,$changearr);
        }
    }
    protected  function RExoprt($data = array(),$fields = array(),$names=array(),$change = [],$tablename='export'){

        $data_lines = [];
        foreach($data as $rz){
            $keydata = [];
            foreach($fields as $rtf){
                if(isset($rz[$rtf]) && $rz[$rtf]==0){
                    $rz[$rtf] .= ' ';
                }
                $keydata[$rtf] = isset($rz[$rtf])?$rz[$rtf]:'';
                if(!empty($change[$rtf])){
                    $keydata[$rtf] = $this->changeExoprt($change[$rtf][0],$keydata[$rtf],$change[$rtf][1]);
                }
                $keydata[$rtf] = str_replace(',','',$keydata[$rtf]);
            }
            $data_lines[] = $keydata;
        }

        $headers = [];
        $stkeys = [

            -1=>'',
            0=>'A',
            1=>'B',
            2=>'C',
            3=>'D',
            4=>'E',
            5=>'F',
            6=>'G',
            7=>'H',
            8=>'I',
            9=>'J',
            10=>'K',
            11=>'L',
            12=>'M',
            13=>'N',
            14=>'O',
            15=>'P',
            16=>'Q',
            17=>'R',
            18=>'S',
            19=>'T',
            20=>'U',
            21=>'V',
            22=>'W',
            23=>'X',
            24=>'Y',
            25=>'Z',
        ];

        foreach ($names as $kr=>$r){
            $st = '';
            $n1 = $kr;
            $sar1 = $n1%26;
            $sar2 = floor($n1/26);
//            if($sar2==0) $aa1  = '';else
            $aa1 = $stkeys[$sar2-1];
            $aa2 = $stkeys[$sar1];
//            if($n1%26==0 && $n1>0) $aa2 = 'Z';
            $st = $aa1.$aa2;

            $headers[$st.'1'] = $r;
        }
//        var_dump($headers);
//        halt($headers);
//        halt([$headers]);
        $this->export($headers,$data_lines,$tablename);
    }
    // 导出
    public function export($header = [], $data = [], $fileName = "",$type = true)
    {
        // 实例化类
        $preadsheet = new Spreadsheet();
        // 创建sheet
        $sheet = $preadsheet->getActiveSheet();
        // 循环设置表头数据
        foreach ($header as $k => $v) {
            $sheet->setCellValue($k, $v);
        }
        // 生成数据
        $sheet->fromArray($data, null, "A2");
        // 样式设置
        $sheet->getDefaultColumnDimension()->setWidth(12);
        // 设置下载与后缀
        if ($type) {
            header("Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
            $type = "Xlsx";
            $suffix = "xlsx";
        } else {
            header("Content-Type:application/vnd.ms-excel");
            $type = "Xls";
            $suffix = "xls";
        }
        // 激活浏览器窗口
        header("Content-Disposition:attachment;filename=$fileName.$suffix");
        //缓存控制
        header("Cache-Control:max-age=0");
        //强制设置头跨域 导出类型区别
        $url = $_SERVER["HTTP_REFERER"]; //获取完整的来路URL
        $str = str_replace("https://","",$url); //去掉http://
        $strdomain = explode("/",$str);  // 以“/”分开成数组
        $domain = $strdomain[0];//取第一个“/”以前的字符

        header('Access-Control-Allow-Origin: https://'.$domain);
        header('Access-Control-Allow-Credentials: true');

        // 调用方法执行下载
        $writer = IOFactory::createWriter($preadsheet, $type);
        // 数据流
        $writer->save("php://output");
        exit;
    }
    //处理排序
    public function useOrderby($list = [],$okey=0){
        if(!empty($list) && is_object($list)) $list = $list->toArray();
        $orderbykey=input('orderbykey',$okey);
        if(!empty($orderbykey)){
            $sortby=input('sortby','desc');
            $sortby  = $sortby=='desc'?SORT_DESC:SORT_ASC;
            $endTimeArr = [];
            foreach($list as $t){

                $endTimeArr[] = $t[$orderbykey];

            }
            array_multisort($endTimeArr,$sortby,$list);
        }
        return $list;
    }
    //处理分页
    public function uesLimitby($list = [],$pageSize=0){
        if(!empty($list) && is_object($list)) $list = $list->toArray();
        $page = input('page',0);
        $pagesize = input('pagesize',$pageSize?$pageSize:10);

        if($page>0){
            $list = array_slice($list,($page-1)*$pagesize,$pagesize);
        }
        return $list;


    }
    public function changeExoprt($type,$val,$st){
        switch ($type){
            case 'addstring':
                $val = $st.$val;
                break;
        }
        return $val;
    }
    //权限获取
    function getqxglDataUser($user_id=0){
        $user_id = $this->request->uid;

        $mid = [];
        $ids_sfs = [];
        $hy_id = Db::table('lncrm_cms_diyform_hangyebangding')->where('sales_user_id',$user_id)->column('hangye');
        $hy_info = Db::table('sj_customer_industry')->where('industry_id','in',$hy_id)->column('industry_name');
        $qu_ls = Db::table('lncrm_cms_diyform_quyushenpijuese')->where('sales_user_id',$user_id)->column('suoshuquyu');
        $sf_ls = Db::table('lncrm_cms_diyform_qudao')->where('sales_user_id',$user_id)->column('addprv');

        $sf_ars = [];

        if(!empty($sf_ls)) $sf_ars = $sf_ls;
        if(!empty($qu_ls)){
            foreach($qu_ls as $t){
                $nrs = Db::table('yf_base_district')->where('district_parent_id',0)->where('district_region',$t)->column('district_name');

                if(!empty($nrs)) {
                    $sf_ars = array_merge($sf_ars,array_values($nrs));
                }
            }
            $sf_ars = array_merge($sf_ars,array_values($qu_ls));
        }
        if(!empty($sf_ars)){
            $nrs = Db::table('yf_base_district')->where('district_name','in',$sf_ars)->column('district_id');
            $nrs_ar = Db::table('yf_base_district')->where('district_parent_id','in',$nrs)->column('district_name');
            $ids_sfs = array_merge($sf_ars,array_values($nrs_ar));
            foreach($ids_sfs as $srr){
                if(!empty($srr))
                    $order_gr_auth_sf_s = Db::table('lncrm_cms_diyform_dailishang')->where('usecity like "%'.$srr.'%"')->column('user_id');
                if(!empty($order_gr_auth_sf_s)) $mid = array_merge($mid,array_values($order_gr_auth_sf_s));
            }

            $sids = Db::table('yf_shop_base')->where('user_id','in',$mid)->column('shop_id');
            $sids = array_values($sids);
            $sids = $sids??[-11];
            $lsid = Db::table('lncrm_cms_diyform_dailishang')->where('master','in',$sids)->column('user_id');
            if(!empty($lsid))$mid = array_merge($mid,array_values($lsid));
            $mid = $mid??[-10010000];
        }
        return ['hy'=>$hy_info,'mid'=>$mid,'areas'=>$ids_sfs];
    }
    function getsqxDataUser($user_id = 0)
    {
        $user_id = $this->request->uid;
        if (empty($user_id)) return ['hy' => [], 'mid' => []];


        $mid = [];
        $ids_sfs = [];
        $hy_id = Db::table('lncrm_cms_diyform_hangyebangding')->where('sales_user_id', $user_id)->column('hangye');
        $hy_info = Db::table('sj_customer_industry')->where('industry_id', 'in', $hy_id)->column('industry_name');
        $qu_ls = Db::table('lncrm_cms_diyform_quyushenpijuese')->where('sales_user_id', $user_id)->column('suoshuquyu');
        $sf_ls = Db::table('lncrm_cms_diyform_qudao')->where('sales_user_id', $user_id)->column('addprv');

        $sf_ars = [];

        if (!empty($sf_ls)) $sf_ars = $sf_ls;
        if (!empty($qu_ls)) {
            foreach ($qu_ls as $t) {
                $nrs = Db::table('yf_base_district')->where('district_parent_id', 0)->where('district_region', $t)->column('district_name');

                if (!empty($nrs)) {
                    $sf_ars = array_merge($sf_ars, array_values($nrs));
                }
            }
        }
        if (!empty($sf_ars)) {
            $nrs = Db::table('yf_base_district')->where('district_name', 'in', $sf_ars)->column('district_id');
            $nrs_ar = Db::table('yf_base_district')->where('district_parent_id', 'in', $nrs)->column('district_name');
            $ids_sfs = array_merge($sf_ars, array_values($nrs_ar));
            foreach ($ids_sfs as $srr) {
                $order_gr_auth_sf_s = Db::table('lncrm_cms_diyform_dailishang')->where('usecity like "%' . $srr . '%"')->column('user_id');
                if (!empty($order_gr_auth_sf_s)) $mid = array_merge($mid, array_values($order_gr_auth_sf_s));
            }
            $sids = Db::table('yf_shop_base')->where('user_id', 'in', $mid)->column('shop_id');
            $sids = array_values($sids);
            $sids = $sids??[-11];
            $lsid = Db::table('lncrm_cms_diyform_dailishang')->where('master', 'in', $sids)->column('user_id');
            if (!empty($lsid)) $mid = array_merge($mid, array_values($lsid));
        }
        return ['hy' => $hy_info, 'mid' => $mid];
    }

}
