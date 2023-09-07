@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Design Workflow')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Design Workflow</li>
@endsection

@push('page-style')
    <style>
        .customizer {
            max-width: 340px;
	    }
	 .setup-icon-box {
		 padding-right:10px; 
	 }
	 .work_flow_title{
		background:transparent;
		border:0;
		border-bottom: 2px solid;
		color: #7165f0;
		font-weight: 500;
		font-size: 20px;
		padding: 0;
		height: 22px;
	 }
	 .work_flow_title:focus,.work_flow_title:active,.work_flow_title:hover{outline:none;border:none;border-bottom: 2px solid;}
	 .work_flow_title_wrapper .work_flow_title{ display:none;}
	 .work_flow_title_wrapper.editing .card-title{ display:none;}
	 .work_flow_title_wrapper.editing .work_flow_title{ display:block;}
	 
	 .cont1,.cont2{height:30px;margin-top:0}
	 .select-app{    
		width: 128px;
		height: 128px;
		*border: 1px solid #d4d3ec;
		border-radius: 8px;
		display: flex;
		align-items: center;
		justify-content: center;
		box-shadow: 0 0 8px 2px #e6e5ff;
		box-shadow: 0 4px 24px 0 rgb(34 41 47 / 10%);
		cursor:pointer;
		text-align: center;
        font-size: 12px;
	 }
	 .select-app .text{  margin-bottom:0 }
	 .select-app .icon.selected{  width:60px;height:60px; }
	 
	 .app-search-area{
		 min-height: calc(100vh - 218px);
	 }
	 .serach-list{
		 list-style: none;
		border: 1px solid #ccc;
		padding: 5px;
		border-radius: 8px;
		
		max-height: calc(100vh - 300px);
		overflow: auto;
	 }
	 
	 .search-item {     
	    padding: 5px;
		background: #f8f8f8;
		border-radius: 5px;
		border: 1px solid #e2e1e1;
		cursor: pointer;
		margin-bottom: 3px; 
		transition: box-shadow .5s;
		
	  }
	  .search-item:hover{ box-shadow: 0 4px 24px 0 rgb(34 41 47 / 20%); background: #fff;   }
	  .search-item.selected:hover{ box-shadow: none; background:f8f8f8; }
	 
	 .search-item .icon img{ width: 30px; height: 30px; }
	 
	 .wf_step1 .select-app.active{border:2px solid var(--themeColor)}
	 .search-form{position: relative;}
	 .search-form .form-control{height: 46px;}
	 .search-form .search-item{
		 position: absolute;
		left: 2px;
		right: 2px;
		top: 2px;
		bottom: 0px;
		z-index:-99;
	 }
	 .search-form .search-item.selected{
		 position: absolute;
		left: 2px;
		right: 2px;
		top: 2px;
		bottom: 0px;
		z-index:2;
	 }
	 
	 .search-form .remove-select { float: right; padding: 4px 5px; }
	 .search-form .remove-select:hover { background:#fff; color:#000;}
	 
	 .s-sloce .card-header-style1{border-bottom-color:var(--themeColor)}
	 
	 .accoBtn{
		 width: 25px;
         height: 25px;
         color: #6e6b7b;
		 cursor:pointer;
		 transition: transform .5s;
	 }
	 .f-25{
		 width: 25px;
         height: 25px;
	 }
	 .s-close .accoBtn{
		 transform: rotate(90deg);
	 }
	 
	 .linkingLine{
		 width: 2px;
		background: var(--themeColor);
		height: 80px;
		display: block;
		position: absolute;
		top: -65px;
		z-index: -1;
		margin: 0 auto;
		left: 50%;
		display:none;
	 }
	 .connect-app{
		     margin-top: 50px;
              position: relative;
	 }
	 .connect-app:before{  
	    content:"";
		width: 85px;
		background: #d2d2d2;
		height: 2px;
		display: block;
		position: absolute;
		top: 15px;
		z-index: 0;
		margin: 0 auto;    
        left: calc(50% - 43px);
        transform: rotateY(0deg);
 		transition: transform .5s;
	 }
     .connect-app:after{  
	    content: "";
		width: 18px;
		background: #fff;
		height: 12px;
		border: 2px solid #d2d2d2;
		border-radius: 15px;
		display: block;
		position: absolute;
		top: 10px;
		z-index: 0;
		margin: 0 auto;
		left: calc(50% - 9px);
	 }
    .connect-app img{opacity:0}

    .more-fileds{display:none;}

	 .valid.connect-app:after{ 
		border: 2px solid #28c76f;;
	  }
     .valid.connect-app:before{
        background: #28c76f;;
     }
	 
	 
	 .serach-item-options{
		 display:none;
		 position: absolute;
		right: 15px;
		left: 15px;
		z-index: 10;
        background: #fff;
		margin-top: -16px;
	 }
	 
	 .linkVerLine{
		width: 2px;
		background: #d2d2d2;
		background: #4caf50;
		height: 20px;
		display: block;
		*position: absolute;
		top: 15px;
		z-index: 0;
		margin: 0 auto;
		left: calc(50% - 43px);
	 }
	 .config-icon{
		     width: 30px;
			height: 30px;
			padding: 4px;
			border-radius: 50%;
			border: 2px solid #4caf50;
			color: #4caf50;
			background: #fff;
	 }
	 .app-config-details{
		 text-align:center;
	 }


     @media(max-width:560px){
           .select-app{    
		      width: 95px;
		      height: 95px;
           }    
           .customizer {
				width: 90%;
			}
			
			.connect-app {
				margin-top: 30px;
			}
     }
    </style>
@endpush

@section('esb-flow-bar')
<div class="customizer d-md-block">
    <!--a class="customizer-toggle d-flex align-items-center justify-content-center" href="javascript:void(0);"><i class="spinner" data-feather="settings"></i></a-->
    <div class="customizer-content">
    <!-- Customizer header -->
        <div class="customizer-header px-2 pt-1 pb-0 position-relative">
          <h4 class="mb-0">ESB App Customizer</h4>
          <p class="m-0">Customize & Setup ESB App</p>

          <a class="customizer-close" href="javascript:void(0);"><i data-feather="x"></i></a>
        </div>

        <hr />

        <!-- App Name -->
        <div class="customizer-styling-direction px-2 mb-2">
          <!--<p class="font-weight-bold mb-0">App Name</p>-->
          <div class="row">
              <div class="col-12"> 
                   <label class="my-label">Select App Position</label>
                  <select class="form-control wf-select" id="select-app-pos">
                      <option value="1" class="app-option">Platform One </option>
                      <option value="2" class="app-option">Platform Two</option>
                  </select>
              <!--input type="text" name="wf_name" class="form-control c_wf_name" placeholder="Step Name.." value="Title here.."/-->
            
              </div>
          </div>
        </div>

        
        <!--Seacrch and Select App Name -->
        <div class="customizer-styling-direction px-2 mb-2 app-search-area" id="app-search-area">
        
        
          <!--<p class="font-weight-bold mb-0">Select App</p>-->
          <div class="row">
          
              <div class="mySearch col-12 ">
                  <div class="select-item mb-2 " >
                    <label class="my-label">Select App...</label>
                    <!--<select data-placeholder="Select an app..." class="select2-icons form-control wf-select" id="select2-icons">
                        <option value=""  data-icon="assets/images/search.png" selected disabled>Select an app... </option>
                        <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                        <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                    </select>-->
                    <div class="search-form" style="position: relative;">
                      <input type="text" name="wf_apps" class="form-control wf_search_input" id="SelectApp" placeholder="Seacrch App Name.." value=""/>
                      <div class="search-item ">
                        <span class="icon"><img src="assets/images/icons/g-sheet.png" /></span> <span class='text'>Google Sheet</span> <a class="remove-select" href="javascript:void(0);"><i data-feather="x"></i></a>
                     </div>
                    </div>
                     
                  </div>
                  
                  <div class="serach-item-options" >
                    <ul class="serach-list"> 
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-sheet.png" /></span> <span class='text'>Google Sheet</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-contact.png" /></span> <span class='text'>Google Contact</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-mail.png" /></span> <span class='text'>Gmail</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-form.png" /></span> <span class='text'>Google From</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-drive.png" /></span> <span class='text'>Google Drive</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-mail.png" /></span> <span class='text'>Gmail</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-calender.png" /></span> <span class='text'>Google Calender</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-slide.png" /></span> <span class='text'>Google Slide</span>
                      </li>
                    </ul>
                  </div>
              </div>
              
              
              <div class="col-12">
                  <div class="more-fileds trigger-wrapper w-100">
                      <div class="select-item mb-2 " >
                        <label class="my-label">Select Trigger</label>
                        
                          <select class="select2 form-control" multiple id='appTigger'>
                              <optgroup label="Alaskan/Hawaiian Time Zone">
                                <option value="AK">Alaska</option>
                                <option value="HI">Hawaii</option>
                              </optgroup>
                              <optgroup label="Pacific Time Zone">
                                <option value="CA">California</option>
                                <option value="NV">Nevada</option>
                                <option value="OR">Oregon</option>
                                <option value="WA">Washington</option>
                              </optgroup>
                              <optgroup label="Mountain Time Zone">
                                <option value="AZ">Arizona</option>
                                <option value="CO" >Colorado</option>
                                <option value="ID">Idaho</option>
                                <option value="MT">Montana</option>
                                <option value="NE">Nebraska</option>
                                <option value="NM">New Mexico</option>
                                <option value="ND">North Dakota</option>
                                <option value="UT">Utah</option>
                                <option value="WY">Wyoming</option>
                              </optgroup>
                              <optgroup label="Central Time Zone">
                                <option value="AL">Alabama</option>
                                <option value="AR">Arkansas</option>
                                <option value="IL">Illinois</option>
                                <option value="IA">Iowa</option>
                                <option value="KS">Kansas</option>
                                <option value="KY">Kentucky</option>
                                <option value="LA">Louisiana</option>
                                <option value="MN">Minnesota</option>
                                <option value="MS">Mississippi</option>
                                <option value="MO">Missouri</option>
                                <option value="OK">Oklahoma</option>
                                <option value="SD">South Dakota</option>
                                <option value="TX">Texas</option>
                                <option value="TN">Tennessee</option>
                                <option value="WI">Wisconsin</option>
                              </optgroup>
                              <optgroup label="Eastern Time Zone">
                                <option value="CT">Connecticut</option>
                                <option value="DE">Delaware</option>
                                <option value="FL" >Florida</option>
                                <option value="GA">Georgia</option>
                                <option value="IN">Indiana</option>
                                <option value="ME">Maine</option>
                                <option value="MD">Maryland</option>
                                <option value="MA">Massachusetts</option>
                                <option value="MI">Michigan</option>
                                <option value="NH">New Hampshire</option>
                                <option value="NJ">New Jersey</option>
                                <option value="NY">New York</option>
                                <option value="NC">North Carolina</option>
                                <option value="OH">Ohio</option>
                                <option value="PA">Pennsylvania</option>
                                <option value="RI">Rhode Island</option>
                                <option value="SC">South Carolina</option>
                                <option value="VT">Vermont</option>
                                <option value="VA">Virginia</option>
                                <option value="WV">West Virginia</option>
                              </optgroup>
                            </select>
                        </div>
                        
                  </div>
              </div>
              
              
              <div class="col-12">
                  <div class="more-fileds account-wrapper w-100">
                      <div class="select-item mb-2 " >
                        <label class="my-label">Select Account</label>
                        <!--<select data-placeholder="Select an app..." class="select2-icons form-control wf-select" id="select2-icons">
                            <option value=""  data-icon="assets/images/search.png" selected disabled>Select an app... </option>
                            <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                            <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                        </select>-->
                        <select data-placeholder="Select an app..." class="form-control " id="appOneAccount">
                            <option value=""  data-icon="assets/images/search.png" selected >Select an Account </option>
                            <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                            <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                            <option value="" data-icon="assets/images/g-sheet.png">Add New Account</option>
                        </select>
                      </div>
                  </div>
              </div>
              
              <div class="col-xl-12 col-sm-12 col-12 mb-2 connect-wrapper">
                    <div class="appOneConnectBtnSidebar" style="display:none;" >
                       <button type="button" class="btn btn-success waves-effect waves-float waves-light loading-button btn-block" data-text='Connect Now' data-clicked='Connecting . . '>
                          <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                       </button>
                    </div>
              </div>
              
              
            
          </div>
        </div>
        
        
        
        <!--Seacrch and Select App Name -->
        <div class="customizer-styling-direction px-2 mb-2 app2-search-area" id="app2-search-area">
        
        
          <!--<p class="font-weight-bold mb-0">Select App</p>-->
          <div class="row">
          
              <div class="mySearch col-12 ">
                  <div class="select-item mb-2 " >
                    <label class="my-label">Select App...</label>
                    <!--<select data-placeholder="Select an app..." class="select2-icons form-control wf-select" id="select2-icons">
                        <option value=""  data-icon="assets/images/search.png" selected disabled>Select an app... </option>
                        <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                        <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                    </select>-->
                    <div class="search-form" style="position: relative;">
                      <input type="text" name="wf_apps" class="form-control wf_search_input" id="SelectApp2" placeholder="Seacrch App Name.." value=""/>
                      <div class="search-item ">
                        <span class="icon"><img src="assets/images/icons/g-sheet.png" /></span> <span class='text'>Google Sheet</span> <a class="remove-select" href="javascript:void(0);"><i data-feather="x"></i></a>
                     </div>
                    </div>
                     
                  </div>
                  
                  <div class="serach-item-options" >
                    <ul class="serach-list"> 
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-sheet.png" /></span> <span class='text'>Google Sheet</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-contact.png" /></span> <span class='text'>Google Contact</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-mail.png" /></span> <span class='text'>Gmail</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-form.png" /></span> <span class='text'>Google From</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-drive.png" /></span> <span class='text'>Google Drive</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-mail.png" /></span> <span class='text'>Gmail</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-calender.png" /></span> <span class='text'>Google Calender</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-slide.png" /></span> <span class='text'>Google Slide</span>
                      </li>
                    </ul>
                  </div>
              </div>
              
              
              <div class="col-12">
                  <div class="more-fileds trigger-wrapper w-100">
                      <div class="select-item mb-2 " >
                        <label class="my-label">Select Trigger</label>
                        
                          <select class="select2 form-control" multiple id='appAction'>
                              <optgroup label="Alaskan/Hawaiian Time Zone">
                                <option value="AK">Alaska</option>
                                <option value="HI">Hawaii</option>
                              </optgroup>
                              <optgroup label="Pacific Time Zone">
                                <option value="CA">California</option>
                                <option value="NV">Nevada</option>
                                <option value="OR">Oregon</option>
                                <option value="WA">Washington</option>
                              </optgroup>
                              <optgroup label="Mountain Time Zone">
                                <option value="AZ">Arizona</option>
                                <option value="CO" >Colorado</option>
                                <option value="ID">Idaho</option>
                                <option value="MT">Montana</option>
                                <option value="NE">Nebraska</option>
                                <option value="NM">New Mexico</option>
                                <option value="ND">North Dakota</option>
                                <option value="UT">Utah</option>
                                <option value="WY">Wyoming</option>
                              </optgroup>
                              <optgroup label="Central Time Zone">
                                <option value="AL">Alabama</option>
                                <option value="AR">Arkansas</option>
                                <option value="IL">Illinois</option>
                                <option value="IA">Iowa</option>
                                <option value="KS">Kansas</option>
                                <option value="KY">Kentucky</option>
                                <option value="LA">Louisiana</option>
                                <option value="MN">Minnesota</option>
                                <option value="MS">Mississippi</option>
                                <option value="MO">Missouri</option>
                                <option value="OK">Oklahoma</option>
                                <option value="SD">South Dakota</option>
                                <option value="TX">Texas</option>
                                <option value="TN">Tennessee</option>
                                <option value="WI">Wisconsin</option>
                              </optgroup>
                              <optgroup label="Eastern Time Zone">
                                <option value="CT">Connecticut</option>
                                <option value="DE">Delaware</option>
                                <option value="FL" >Florida</option>
                                <option value="GA">Georgia</option>
                                <option value="IN">Indiana</option>
                                <option value="ME">Maine</option>
                                <option value="MD">Maryland</option>
                                <option value="MA">Massachusetts</option>
                                <option value="MI">Michigan</option>
                                <option value="NH">New Hampshire</option>
                                <option value="NJ">New Jersey</option>
                                <option value="NY">New York</option>
                                <option value="NC">North Carolina</option>
                                <option value="OH">Ohio</option>
                                <option value="PA">Pennsylvania</option>
                                <option value="RI">Rhode Island</option>
                                <option value="SC">South Carolina</option>
                                <option value="VT">Vermont</option>
                                <option value="VA">Virginia</option>
                                <option value="WV">West Virginia</option>
                              </optgroup>
                            </select>
                        </div>
                        
                  </div>
              </div>
              
              
              <div class="col-12">
                  <div class="more-fileds account-wrapper w-100">
                      <div class="select-item mb-2 " >
                        <label class="my-label">Select Account</label>
                        <!--<select data-placeholder="Select an app..." class="select2-icons form-control wf-select" id="select2-icons">
                            <option value=""  data-icon="assets/images/search.png" selected disabled>Select an app... </option>
                            <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                            <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                        </select>-->
                        <select data-placeholder="Select an app..." class="form-control " id="appTwoAccount">
                            <option value=""  data-icon="assets/images/search.png" selected >Select an Account </option>
                            <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                            <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                            <option value="" data-icon="assets/images/g-sheet.png">Add New Account</option>
                        </select>
                      </div>
                  </div>
              </div>
              
              <div class="col-xl-12 col-sm-12 col-12 mb-2 connect-wrapper">
                    <div class="appTwoConnectBtnSidebar" style="display:none;" >
                       <button type="button" class="btn btn-success waves-effect waves-float waves-light loading-button btn-block" data-text='Connect Now' data-clicked='Connecting . . '>
                          <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                       </button>
                    </div>
              </div>
              
              
            
          </div>
        </div>
        
        
        
        
        
        
        <!--Seacrch and Select App Name -->
        <div class="customizer-styling-direction px-2 mb-2 app-setting-area" id="app-setting-area" style="display:none;">
          <!--<p class="font-weight-bold mb-0">Select App</p>-->
          <div class="row">
          
              <div class="mySearch col-12 ">
                  <div class="select-item mb-1 " >
                    <label class="my-label">Select App Settings </label>
                    <!--<select data-placeholder="Select an app..." class="select2-icons form-control wf-select" id="select2-icons">
                        <option value=""  data-icon="assets/images/search.png" selected disabled>Select an app... </option>
                        <option value="Google Calender" data-icon="assets/images/g-calender.png" >Google Calender </option>
                        <option value="Google Sheet" data-icon="assets/images/g-sheet.png">Google Sheet</option>
                    </select>-->
                    <div class="search-form" style="position: relative;">
                      <input type="text" name="wf_apps" class="form-control wf_search_input" placeholder="Seacrch App Name.." value=""/>
                      <div class="search-item ">
                        <span class="icon"><img src="assets/images/icons/g-sheet.png" /></span> <span class='text'>Google Sheet</span> <a class="remove-select" href="javascript:void(0);"><i data-feather="x"></i></a>
                     </div>
                    </div>
                     
                  </div>
                  
                  <div class="serach-item-options" >
                    <ul class="serach-list"> 
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-sheet.png" /></span> <span class='text'>Google Sheet</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-contact.png" /></span> <span class='text'>Google Contact</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-mail.png" /></span> <span class='text'>Gmail</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-form.png" /></span> <span class='text'>Google From</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-drive.png" /></span> <span class='text'>Google Drive</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-mail.png" /></span> <span class='text'>Gmail</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-calender.png" /></span> <span class='text'>Google Calender</span>
                      </li>
                      <li class="search-item" data-id="">
                        <span class="icon"><img src="assets/images/icons/g-slide.png" /></span> <span class='text'>Google Slide</span>
                      </li>
                      
                    </ul>
                  </div>
              </div>
            
          </div>
        </div>
      </div>

  </div>
@endsection

@section('page-content')

          <!-- Workflow Section -->
          <section id="make-a-esb" class="mw-900" >
          
            <div class="row match-height ">
              
              <!-- workflow Card -->
              <div class="col-xl-12 col-md-12 col-12" >
                <div class="card card-statistics" id="wf_step1">
                  <div class="card-header card-header-style1">
                    <div class="step-count setup-icon-box">
                       <span><img src="{{asset('public/esb_asset/assets/images/svg/app-2.svg')}}" width="30" height="30" class="svg-purple"></span>
                       
                    </div>
                    <div style="flex: auto;" class="work_flow_title_wrapper ">
                       <span><h4 class="card-title">Select App Platforms</h4></span>
                       <input type="text" class="work_flow_title" value="Title here..">
                    </div>
                    
                    <i data-feather="chevron-down" class="accoBtn"></i>
                    
                    
                    <!--<div class="dropdown chart-dropdown">
                      
                      <i data-feather="more-vertical" class=" f-25" data-toggle="dropdown"></i>
                    
                      <div class="dropdown-menu dropdown-menu-right" style="">
                        <a class="dropdown-item renameApp" href="javascript:void(0);">Rename</a>
                      </div>
                    </div>-->
                  </div>
                  
                  <div class="card-body statistics-body ">
                    <div class="row justify-content-center pt-2">
                      <div class="col-xl-12 col-sm-12 col-12">
                         <h1 class="text-center text-dark">Select Your Workflow Apps</h1>
                         <p class="text-center">Know exactly what you want to build? Select the apps you want to connect to start your custom setup.</p>
                      </div>
                   </div>
                   
                   
                   <div class="row justify-content-center align-items-top py-1 wf_step1 done" >
                        <div class="col-xl-auto col-sm-auto col-auto mb-2 mb-xl-0">
                           <div class="select-app app1" data-app='1' data-id="" data-icon='' data-text='' >
                             <span><img class="icon svg-purple" src="{{asset('public/esb_asset/assets/images/svg/app-2.svg')}}" width="30" height="30">
                             <p class="text">Select app </p><span>
                             <input type="hidden" id="connectWithApp">  
                            </div>
                            
                            <div class="app-config-details" >
                                <div class="trigger-info appOneIcon" style="display:none;" >
                                   <div class="linkVerLine"></div>
                                   <i data-feather='zap' class='config-icon' ></i>
                                </div>
                                <div class="account-info appOneIcon" style="display:none;">
                                   <div class="linkVerLine"></div>
                                   <i data-feather='user' class='config-icon' ></i> 
                                </div>
                            </div>
                            
                            
                        </div>
                        <div class="col-xl-auto col-sm-auto col-auto mb-2 mb-xl-0">
                           <div class="connect-app"><img src="{{asset('public/esb_asset/assets/images/cc1.png')}}" class="connection-icon cont1 " style="">  </div>
                        </div>
                        <div class="col-xl-auto col-sm-auto col-auto mb-2 mb-xl-0">
                           <div class="select-app app2" data-app='2' data-id="" data-icon='' data-text='' >
                             <span><img class="icon svg-purple" src="{{asset('public/esb_asset/assets/images/svg/app-2.svg')}}" width="30" height="30" >
                             <p class="text">Select app </p><span>
                             <input type="hidden" id="connectToApp">
                           </div>
                           
                            <div class="app-config-details" data-app='2'>
                                <div class="trigger-info appTwoIcon" style="display:none;">
                                   <div class="linkVerLine"></div>
                                   <i data-feather='zap' class='config-icon' ></i>
                                </div>
                                <div class="account-info appTwoIcon" style="display:none;">
                                   <div class="linkVerLine"></div>
                                   <i data-feather='user' class='config-icon' ></i> 
                                </div>
                            </div>
                            
                        </div>
                        
                        <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
                            <div class="actionBtn" style="display:none">
                               <button type="button" class="btn btn-success waves-effect waves-float waves-light loading-button" style='min-width:150px' data-text='Finish' data-clicked='Finishing . . '>
                                  <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Finish</span>
                               </button>
                            </div>
                         </div>
                   </div>
                   
                   
                   
                   
                    
                    
                  </div>
                </div>
              </div>
              <!--/ Step 2 Card -->
              
              <div class="col-xl-12 col-md-12 col-12" >
                <div class="card card-statistics step-2" id="wf_step2" style="display:none;">
                   <span class="linkingLine"></span>
                  <div class="card-header card-header-style1">
                    <div class="step-count setup-icon-box">
                     
                       <span><img src="{{asset('public/esb_asset/assets/images/svg/settings-2.svg')}}" width="35" height="35" class="svg-purple"></span>
                       
                    </div>
                    <div style="flex: auto;" class="work_flow_title_wrapper ">
                       <span><h4 class="card-title">Action Setup..</h4></span>
                       <input type="text" class="work_flow_title" value="Title here..">
                    </div>
                    
                    <i data-feather="chevron-down" class="accoBtn"></i>
                    
                    <!--<div class="dropdown chart-dropdown">
                      <i data-feather="more-vertical" class="f-25" data-toggle="dropdown" ></i>
                      
                      <div class="dropdown-menu dropdown-menu-right" style="">
                        <a class="dropdown-item renameApp" href="javascript:void(0);">Rename</a>
                      </div>
                    </div>-->
                  </div>
                  
                  <div class="card-body statistics-body ">
                  
                        <div class="row justify-content-center pt-2">
                          <div class="col-xl-12 col-sm-12 col-12">
                             <h1 class="text-center text-dark">Select Action with Event</h1>
                             <p class="text-center">Know exactly what you want to build? Select the apps you want to connect to start your custom setup.</p>
                          </div>
                       </div>
                   
                   
                       <div class="row justify-content-center align-items-top py-1 wf_step1 done" >
                            <div class="col-xl-auto col-sm-auto col-auto mb-2 mb-xl-0">
                               <div class="select-app app1" data-app='3' data-id="" data-icon='' data-text='' >
                                 <span><img class="icon svg-purple" src="{{asset('public/esb_asset/assets/images/svg/settings-2.svg')}}" width="30" height="30">
                                 <p class="text">Select Triger </p><span>
                                 <input type="hidden" id="settingWithApp">  
                                </div>
                            </div>
                            <div class="col-xl-auto col-sm-auto col-auto mb-2 mb-xl-0">
                               <div class="connect-app"><img src="{{asset('public/esb_asset/assets/images/svg/arrow.svg')}}" class="connection-icon cont2 " style="width:30px">  </div>
                            </div>
                            <div class="col-xl-auto col-sm-auto col-auto mb-2 mb-xl-0">
                               <div class="select-app app2" data-app='4' data-id="" data-icon='' data-text='' >
                                 <span><img class="icon svg-purple" src="{{asset('public/esb_asset/assets/images/svg/settings-2.svg')}}" width="30" height="30" >
                                 <p class="text">Select Triger </p><span>
                                 <input type="hidden" id="settingToApp">
                               </div>
                            </div>
                            <div class="col-xl-12 col-sm-12 col-auto my-2 mb-xl-0 text-center">
                                <div class="settingBtn" style="display:none">
                                   <button type="button" class="btn btn-success waves-effect waves-float waves-light loading-button" data-text='Connect Now' data-clicked='Connecting . . '>
                                      <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                                   </button>
                                </div>
                             </div>
                       </div>
                  
      
                  </div>
                  
                </div>
              </div>
              
              
            </div>

           
           </section>
 
@endsection

@push('page-script')
<script>
  $(window).on('load',  function(){
if (feather) {
  feather.replace({ width: 14, height: 14 });
}
});



 //select2 with icons

! function (e, t, s) {
"use strict";
var r = s(".select2-icons"), i = s(".select2");
      //var url = window.location.origin+"BSE/{{asset('public/esb_asset/assets/images/";

function u(e) {
  e.element;
  //return e.id ? feather.icons[s(e.element).data("icon")].toSvg() + e.text : e.text
  //console.log(e.text + ' ', s(e.element).data('icon'));
  
  if( s(e.element).data('icon')==undefined){
    return "<span class='text'>" + e.text + "</span>";
  }else{
    return "<img src='"+ s(e.element).data('icon')+"'><span class='text'>" + e.text + "</span>";
  }
  
  //return "<img src=''>" + e.text ;
}
// i.each((function () {
//   var e = s(this);
//   e.wrap('<div class="position-relative "></div>'), e.select2({
//     dropdownAutoWidth: !0,
//     width: "100%",
//     dropdownParent: e.parent()
//   })
// })), r.each((function () {
//   var e = s(this);
//   e.wrap('<div class="position-relative select-with-img"></div>'), e.select2({
//     dropdownAutoWidth: !0,
//     width: "100%",
//     minimumResultsForSearch: 0 / 0,
//     dropdownParent: e.parent(),
//     templateResult: u,
//     templateSelection: u,
//     placeholder: "Search for a repository",
//     escapeMarkup: function (e) {
//       return e
//     },
//     minimumInputLength: 0
//   })
// }));

$('.app1:eq(0)').trigger('click');

$(".to-platform,.from-platform").each(function(){
			MakeSelectPlatform($(this));
		})
		
		function MakeSelectPlatform(e){
			e.wrap('<div class="position-relative select-with-img"></div>'), 
			e.select2({
				dropdownAutoWidth: !0,
				width: "100%",
				minimumResultsForSearch: 0 / 0,
				dropdownParent: e.parent(),
				templateResult: u,
				templateSelection: u,
				placeholder: "Search for a repository",
				escapeMarkup: function (e) {
					return e
				},
				minimumInputLength: 0
			})
		}

//work flow app steps 
jQuery(".customizer-toggle, .customizer-close").click(function(){

  if(jQuery(".customizer").hasClass('open')){
    jQuery(".customizer").removeClass('open');
    jQuery('.select-app').removeClass('active');
  }else{
    jQuery(".customizer").addClass('open');
  }
  //alert("ok");
});
jQuery(".select-app").click(function(){
   
  const app= jQuery(this).data('app'); 
  jQuery('#select-app-pos').val(app);
  
  //check step setting area
  //console.log(app);
  if(app==1){ 
    jQuery('.app-search-area').show();
              jQuery('.app2-search-area').hide(); 					
  }else{ 
      jQuery('.app-search-area').hide();
    jQuery('.app2-search-area').show(); 
  }
  
  //jQuery(".select-app").removeClass('active'); 
  
  if( jQuery(".customizer").hasClass('open') && jQuery(this).hasClass('active') ){
    jQuery(".customizer").removeClass('open');
    jQuery(this).removeClass('active');
  }else if( !jQuery(this).hasClass('active') && jQuery(".customizer").hasClass('open') ){
    jQuery('.select-app').removeClass('active');
    jQuery(this).addClass('active');
  }else{
    jQuery(this).addClass('active');
    jQuery(".customizer").addClass('open');
  }
  
  
  
  
});



//search form
jQuery(".search-form .remove-select").click(function(){
  jQuery(this).parent().removeClass('selected');
  jQuery(this).parents('.search-form').find('input').val('').focus();
});

jQuery(".search-form .wf_search_input").focus(function(){
  
});

//search options

jQuery('.search-form .wf_search_input').focusin(  
  function(){  
  jQuery(this).parents('.mySearch').find('.serach-item-options').slideDown();  
  SearchaAppOption();
  }).focusout(  
  function(){  
  jQuery('.serach-item-options').slideUp();
  SearchaAppOption();
});


jQuery('#SelectApp').change(function(){
  SearchaAppOption();
});


//show hide account options
const SearchaAppOption =()=>{
  
  const v = jQuery('#SelectApp').val();
  const v2 = jQuery('#select-app-pos').val();
  //console.log('selected app', v);
    if(v!='' && v2=='2'){  
    jQuery('#app2-search-area .more-fileds').slideDown();  
    }else if(v!='' && v2=='1'){
    jQuery('#app-search-area .more-fileds').slideDown(); 
    }else{
    jQuery('.more-fileds').slideUp();    
    }  
  
}

//******************  sidebar app one config setting **************//
jQuery('#appTigger').change(function(){
  const v = jQuery(this).val();
  //alert("ok");
  //console.log('selected app', v);
    if(v!=''){  
    jQuery('.appOneIcon.trigger-info').slideDown(); 
               //alert(v);					
    }else{
    jQuery('.appOneIcon.trigger-info').slideUp();    
    }  
    
    app1ConnectOption();
});

jQuery('#appOneAccount').change(function(){
  const v = jQuery(this).val();
  
  //console.log('selected app', v);
    if(v!=''){  
    jQuery('.appOneIcon.account-info').slideDown();  
    }else{
    jQuery('.appOneIcon.account-info').slideUp();    
    }  
    
    app1ConnectOption();
});

const app1ConnectOption = () =>{
  
  const v1 = jQuery('#appOneAccount').val();
  const v2 = jQuery('#appTigger').val();
  
   if( v1 !='' && v2 !=''){  
    jQuery('.appOneConnectBtnSidebar').slideDown();  
    }else{
    jQuery('.appOneConnectBtnSidebar').slideUp();    
    }  
  
}

jQuery('.appOneConnectBtnSidebar button').click(function(){
  
    var btn = jQuery(this);
  
    setTimeout(function(){ 
      

                btn.find('.text').text(btn.data('text'));
                btn.find('.loading-icon').hide();
      
      
      //set setp2
      
        jQuery('#select-app-pos').focus(); 
        jQuery('#select-app-pos').val('2'); 
      
        jQuery('.select-app').removeClass('active'); 
        jQuery('.customizer').removeClass('open'); 
      
     
      

    }, 3000);
  
});





//******************  sidebar app Two config setting **************//


jQuery('#appAction').change(function(){
  const v = jQuery(this).val();
  //alert("ok");
  //console.log('selected app', v);
    if(v!=''){  
    jQuery('.appTwoIcon.trigger-info').slideDown(); 
               //alert(v);					
    }else{
    jQuery('.appTwoIcon.trigger-info').slideUp();    
    }  
    
    app2ConnectOption();
});

jQuery('#appTwoAccount').change(function(){
  const v = jQuery(this).val();
  
  //console.log('selected app', v);
    if(v!=''){  
    jQuery('.appTwoIcon.account-info').slideDown();  
    }else{
    jQuery('.appTwoIcon.account-info').slideUp();    
    }  
    
    app2ConnectOption();
    
    ValidCheck();
});



const app2ConnectOption = () =>{
  
  const v1 = jQuery('#appTwoAccount').val();
  const v2 = jQuery('#appAction').val();
  
  //alert()
  
   if( v1 !='' && v2 !=''){  
    jQuery('.appTwoConnectBtnSidebar').slideDown();  
    }else{
    jQuery('.appTwoConnectBtnSidebar').slideUp();    
    }  
  
    ValidCheck();
  
}


jQuery('.appTwoConnectBtnSidebar button').click(function(){
  
    var btn = jQuery(this);
  
    setTimeout(function(){ 
     

                btn.find('.text').text(btn.data('text'));
                btn.find('.loading-icon').hide();
      
        jQuery('.select-app').removeClass('active'); 
        jQuery('.customizer').removeClass('open'); 
      
      

    }, 3000);
  
});







jQuery(".serach-item-options .search-item").click(function(){
  //alert("ok");
  
  const icon = jQuery(this).find('.icon img').attr('src');
  const txt = jQuery(this).find('.text').text(); 
  const id = jQuery(this).data('id');
  
  jQuery(this).parents('.mySearch').find('.search-form input').val(txt);
  
  
  jQuery(this).parents('.mySearch').find('.search-form .search-item .icon img').attr('src', icon);
  jQuery(this).parents('.mySearch').find('.search-form .search-item .text').text(txt);
  //jQuery(this).parents('.mySearch').find('.wf_search_input').val(txt);
  jQuery(this).parents('.mySearch').find('.search-form .search-item').addClass('selected');
  
  slectApp(icon,txt,id);
  SearchaAppOption();
});

//set app 
const slectApp =(icon,txt,id)=>{  

    const pos = jQuery('#select-app-pos').val();
  var place = '';
    //console.log(pos);
  if(pos<3){
        place = jQuery('#wf_step1 .select-app[data-app='+pos+']');
  }else{
    place = jQuery('#wf_step2 .select-app[data-app='+pos+']');
  }
  
  
  place.attr('data-icon', icon);
  place.attr('data-text', txt);
  place.attr('data-id', id);
  
  place.find('.icon').attr('src', icon).removeClass('svg-purple').addClass('selected');
  place.find('.text').text(txt);
  place.find('input').val(txt);
  //place.append(txt);
  
  console.log(icon,txt,id,pos); 

          //alert(jQuery('#connect-with-app').val());				

  
}


//setting app selector 
jQuery("#select-app-pos").change(function(){
  
  const v = jQuery(this).val();
  //alert(v);
  jQuery('.select-app').removeClass('active');
  jQuery('.select-app[data-app='+v+']').addClass('active');
  
  if(v==1){
    jQuery('#app-search-area').show();
    jQuery('#app2-search-area').hide();
  }else{
    jQuery('#app2-search-area').show();
    jQuery('#app-search-area').hide();
  }
  
  
  
  
  
});

//check setp1
const ValidCheck=()=>{  
  
  const app1 = jQuery('#connectWithApp').val();
    const app11 = jQuery('#appTigger').val();
    const app12 = jQuery('#appOneAccount').val();
  
  
  
    const app2 = jQuery('#connectToApp').val();
    const app21 = jQuery('#appAction').val();
    const app22 = jQuery('#appTwoAccount').val();
  
  
  
  console.log(app1, app2)

  
  if(app1 !='' && app11 !='' && app12 !='' &&  app2 !='' && app21 !='' && app22 !=''){
    jQuery(".actionBtn").slideDown();
    jQuery(".cont1").parent().addClass('valid');
    //alert("ok");
  }else{
    jQuery(".actionBtn").slideUp();
    jQuery(".cont1").parent().removeClass('valid');
  }
  
  /*if(app3 !='' && app4 !=''){
    jQuery(".settingBtn").slideDown();
    jQuery(".cont2").parent().addClass('valid');
    //alert("ok");
  }else{
    jQuery(".settingBtn").slideUp();
  }*/
}

//appActionBtn setp1
jQuery('.actionBtn button').click(function(){
  
    jQuery('.connection-icon.cont1').addClass('valid');
    //close right-sidebar
    jQuery(".customizer").removeClass('open');
    jQuery('.select-app').removeClass('active');

            const btn = jQuery(this);
    
    setTimeout(function(){ 
       //jQuery('#wf_step1 .card-body').slideUp(); 
       //jQuery('#wf_step1').addClass('s-close'); 
     
       //jQuery('#wf_step2, .linkingLine').slideDown(); 

                btn.find('.text').text(btn.data('text'));
                btn.find('.loading-icon').hide();

    }, 3000);
    
});


//appSettingBtn setp2
/*jQuery('.settingBtn button').click(function(){
  
    jQuery('.connection-icon.cont2').addClass('valid');
    //close right-sidebar
    jQuery(".customizer").removeClass('open');
    jQuery('.select-app').removeClass('active');

           const btn = jQuery(this);
    
    setTimeout(function(){ 
       jQuery('#wf_step2 .card-body').slideUp(); 
       jQuery('#wf_step2').addClass('s-close'); 

               btn.find('.text').text(btn.data('text'));
               btn.find('.loading-icon').hide();
     
    }, 3000);
});*/

jQuery(document).on('click','.accoBtn', function(){

    jQuery(this).parents('.card').find('.card-body').slideToggle();
    jQuery(this).parents('.card').toggleClass("s-close");
    //alert("ok");
   
});



}(window, document, jQuery);

//select2 with icons end




</script>
@endpush
