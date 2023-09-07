@if(!isset(\Config::get('apisettings.hideElmOrFeatureForDomain')[config('org_details.org_identity')]))
<nav class="header-navbar navbar navbar-expand-lg align-items-center floating-nav navbar-light navbar-shadow">
    @php
        $authUserName = Auth::user()->name;
        $nameParts = explode(' ', $authUserName);
        $hdrFirstName = $nameParts[0];
        $hdrPicPath = Auth::user()->profile_pic_path;
        if(!empty($hdrPicPath) && file_exists($hdrPicPath)){
            $hdrPic = basename($hdrPicPath);
        }
        else{
            $hdrPic = asset('public/esb_asset/images/avatars/profile-icon.png');
        }
        $master_user_record=false;
        $master_user=Session::get('switch_to_user_dashboard');
     
        if($master_user){
           $master_username=Session::get('master_username');
           $master_user_record=true;
        }

        if(Session::get('switch_to_staff_dashboard')){
           $master_user = Session::get('switch_to_staff_dashboard');
           $master_username=Session::get('staff_username');
           $master_user_record=true;
        }
@endphp
    <div class="navbar-container d-flex content">
      <div class="bookmark-wrapper d-flex align-items-center">
        <ul class="nav navbar-nav d-xl-none">
          <li class="nav-item"><a class="nav-link menu-toggle" href="javascript:void(0);"><i class="ficon" data-feather="menu"></i></a></li>
        </ul>
        <ul class="nav navbar-nav bookmark-icons">
              <!-- <li class="nav-item nav-search">
               <a class="nav-link nav-link-search"><i class="ficon" data-feather="search"></i></a>
              <div class="search-input">
                <div class="search-input-icon"><i data-feather="search"></i></div>
                <input class="form-control input" type="text" placeholder="Explore Vuexy..." tabindex="-1" data-search="search">
                <div class="search-input-close"><i data-feather="x"></i></div>
                <ul class="search-list search-list-main"></ul>
              </div>
            </li> -->
             @if($master_user_record)
          <li class="nav-item d-none d-lg-block "><span class="nav-link text-warning"  data-toggle="tooltip" data-placement="top" ><i class="ficon" data-feather="user"></i> ! You are logged in account's of <strong>{{$hdrFirstName}}</strong></span></li>
          @endif
          <!--
          <li class="nav-item d-none d-lg-block"><a class="nav-link" href="app-chat.html" data-toggle="tooltip" data-placement="top" title="Chat"><i class="ficon" data-feather="message-square"></i></a></li>
          <li class="nav-item d-none d-lg-block"><a class="nav-link" href="app-calendar.html" data-toggle="tooltip" data-placement="top" title="Calendar"><i class="ficon" data-feather="calendar"></i></a></li>
          <li class="nav-item d-none d-lg-block"><a class="nav-link" href="app-todo.html" data-toggle="tooltip" data-placement="top" title="Todo"><i class="ficon" data-feather="check-square"></i></a></li>-->
        </ul>
        <!--<ul class="nav navbar-nav">
          <li class="nav-item d-none d-lg-block"><a class="nav-link bookmark-star"><i class="ficon text-warning" data-feather="star"></i></a>
            <div class="bookmark-input search-input">
              <div class="bookmark-input-icon"><i data-feather="search"></i></div>
              <input class="form-control input" type="text" placeholder="Bookmark" tabindex="0" data-search="search">
              <ul class="search-list search-list-bookmark"></ul>
            </div>
          </li>
        </ul>-->
      </div>
      <ul class="nav navbar-nav align-items-center ml-auto">
        <!--<li class="nav-item dropdown dropdown-language"><a class="nav-link dropdown-toggle" id="dropdown-flag" href="javascript:void(0);" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><i class="flag-icon flag-icon-us"></i><span class="selected-language">English</span></a>
          <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdown-flag"><a class="dropdown-item" href="javascript:void(0);" data-language="en"><i class="flag-icon flag-icon-us"></i> English</a><a class="dropdown-item" href="javascript:void(0);" data-language="fr"><i class="flag-icon flag-icon-fr"></i> French</a><a class="dropdown-item" href="javascript:void(0);" data-language="de"><i class="flag-icon flag-icon-de"></i> German</a><a class="dropdown-item" href="javascript:void(0);" data-language="pt"><i class="flag-icon flag-icon-pt"></i> Portuguese</a></div>
        </li>
        <li class="nav-item d-none d-lg-block"><a class="nav-link nav-link-style"><i class="ficon" data-feather="moon"></i></a></li>
        -->

        <!--<li class="nav-item dropdown dropdown-cart mr-25"><a class="nav-link" href="javascript:void(0);" data-toggle="dropdown"><i class="ficon" data-feather="shopping-cart"></i><span class="badge badge-pill badge-primary badge-up cart-item-count">6</span></a>
          <ul class="dropdown-menu dropdown-menu-media dropdown-menu-right">
            <li class="dropdown-menu-header">
              <div class="dropdown-header d-flex">
                <h4 class="notification-title mb-0 mr-auto">My Cart</h4>
                <div class="badge badge-pill badge-light-primary">4 Items</div>
              </div>
            </li>
            <li class="scrollable-container media-list">
              <div class="media align-items-center"><img class="d-block rounded mr-1" src="app-assets/images/pages/eCommerce/1.png" alt="donuts" width="62">
                <div class="media-body"><i class="ficon cart-item-remove" data-feather="x"></i>
                  <div class="media-heading">
                    <h6 class="cart-item-title"><a class="text-body" href="app-ecommerce-details.html"> Apple watch 5</a></h6><small class="cart-item-by">By Apple</small>
                  </div>
                  <div class="cart-item-qty">
                    <div class="input-group">
                      <input class="touchspin-cart" type="number" value="1">
                    </div>
                  </div>
                  <h5 class="cart-item-price">$374.90</h5>
                </div>
              </div>
              <div class="media align-items-center"><img class="d-block rounded mr-1" src="app-assets/images/pages/eCommerce/7.png" alt="donuts" width="62">
                <div class="media-body"><i class="ficon cart-item-remove" data-feather="x"></i>
                  <div class="media-heading">
                    <h6 class="cart-item-title"><a class="text-body" href="app-ecommerce-details.html"> Google Home Mini</a></h6><small class="cart-item-by">By Google</small>
                  </div>
                  <div class="cart-item-qty">
                    <div class="input-group">
                      <input class="touchspin-cart" type="number" value="3">
                    </div>
                  </div>
                  <h5 class="cart-item-price">$129.40</h5>
                </div>
              </div>
              <div class="media align-items-center"><img class="d-block rounded mr-1" src="app-assets/images/pages/eCommerce/2.png" alt="donuts" width="62">
                <div class="media-body"><i class="ficon cart-item-remove" data-feather="x"></i>
                  <div class="media-heading">
                    <h6 class="cart-item-title"><a class="text-body" href="app-ecommerce-details.html"> iPhone 11 Pro</a></h6><small class="cart-item-by">By Apple</small>
                  </div>
                  <div class="cart-item-qty">
                    <div class="input-group">
                      <input class="touchspin-cart" type="number" value="2">
                    </div>
                  </div>
                  <h5 class="cart-item-price">$699.00</h5>
                </div>
              </div>
              <div class="media align-items-center"><img class="d-block rounded mr-1" src="app-assets/images/pages/eCommerce/3.png" alt="donuts" width="62">
                <div class="media-body"><i class="ficon cart-item-remove" data-feather="x"></i>
                  <div class="media-heading">
                    <h6 class="cart-item-title"><a class="text-body" href="app-ecommerce-details.html"> iMac Pro</a></h6><small class="cart-item-by">By Apple</small>
                  </div>
                  <div class="cart-item-qty">
                    <div class="input-group">
                      <input class="touchspin-cart" type="number" value="1">
                    </div>
                  </div>
                  <h5 class="cart-item-price">$4,999.00</h5>
                </div>
              </div>
              <div class="media align-items-center"><img class="d-block rounded mr-1" src="app-assets/images/pages/eCommerce/5.png" alt="donuts" width="62">
                <div class="media-body"><i class="ficon cart-item-remove" data-feather="x"></i>
                  <div class="media-heading">
                    <h6 class="cart-item-title"><a class="text-body" href="app-ecommerce-details.html"> MacBook Pro</a></h6><small class="cart-item-by">By Apple</small>
                  </div>
                  <div class="cart-item-qty">
                    <div class="input-group">
                      <input class="touchspin-cart" type="number" value="1">
                    </div>
                  </div>
                  <h5 class="cart-item-price">$2,999.00</h5>
                </div>
              </div>
            </li>
            <li class="dropdown-menu-footer">
              <div class="d-flex justify-content-between mb-1">
                <h6 class="font-weight-bolder mb-0">Total:</h6>
                <h6 class="text-primary font-weight-bolder mb-0">$10,999.00</h6>
              </div><a class="btn btn-primary btn-block" href="app-ecommerce-checkout.html">Checkout</a>
            </li>
          </ul>
        </li>-->
        <li class="nav-item dropdown dropdown-notification mr-25">
        {{-- <a class="nav-link" href="javascript:void(0);" data-toggle="dropdown"><i class="ficon" data-feather="bell"></i><span class="badge badge-pill badge-danger badge-up">5</span></a> --}}
          <ul class="dropdown-menu dropdown-menu-media dropdown-menu-right">
            {{-- <li class="dropdown-menu-header">
              <div class="dropdown-header d-flex">
                <h4 class="notification-title mb-0 mr-auto">Notifications</h4>
                <div class="badge badge-pill badge-light-primary">6 New</div>
              </div>
            </li> --}}
            {{-- <li class="scrollable-container media-list"><a class="d-flex" href="javascript:void(0)">
                <div class="media d-flex align-items-start">
                  <div class="media-left">
                    <div class="avatar"><img src="app-assets/images/portrait/small/avatar-s-15.jpg" alt="avatar" width="32" height="32"></div>
                  </div>
                  <div class="media-body">
                    <p class="media-heading"><span class="font-weight-bolder">Congratulation Sam 🎉</span>winner!</p><small class="notification-text"> Won the monthly best seller badge.</small>
                  </div>
                </div></a><a class="d-flex" href="javascript:void(0)">
                <div class="media d-flex align-items-start">
                  <div class="media-left">
                    <div class="avatar"><img src="app-assets/images/portrait/small/avatar-s-3.jpg" alt="avatar" width="32" height="32"></div>
                  </div>
                  <div class="media-body">
                    <p class="media-heading"><span class="font-weight-bolder">New message</span>&nbsp;received</p><small class="notification-text"> You have 10 unread messages</small>
                  </div>
                </div></a><a class="d-flex" href="javascript:void(0)">
                <div class="media d-flex align-items-start">
                  <div class="media-left">
                    <div class="avatar bg-light-danger">
                      <div class="avatar-content">MD</div>
                    </div>
                  </div>
                  <div class="media-body">
                    <p class="media-heading"><span class="font-weight-bolder">Revised Order </span>&nbsp;checkout</p><small class="notification-text"> MD Inc. order updated</small>
                  </div>
                </div></a>
              <div class="media d-flex align-items-center">
                <h6 class="font-weight-bolder mr-auto mb-0">System Notifications</h6>
                <div class="custom-control custom-control-primary custom-switch">
                  <input class="custom-control-input" id="systemNotification" type="checkbox" checked="">
                  <label class="custom-control-label" for="systemNotification"></label>
                </div>
              </div><a class="d-flex" href="javascript:void(0)">
                <div class="media d-flex align-items-start">
                  <div class="media-left">
                    <div class="avatar bg-light-danger">
                      <div class="avatar-content"><i class="avatar-icon" data-feather="x"></i></div>
                    </div>
                  </div>
                  <div class="media-body">
                    <p class="media-heading"><span class="font-weight-bolder">Server down</span>&nbsp;registered</p><small class="notification-text"> USA Server is down due to hight CPU usage</small>
                  </div>
                </div></a><a class="d-flex" href="javascript:void(0)">
                <div class="media d-flex align-items-start">
                  <div class="media-left">
                    <div class="avatar bg-light-success">
                      <div class="avatar-content"><i class="avatar-icon" data-feather="check"></i></div>
                    </div>
                  </div>
                  <div class="media-body">
                    <p class="media-heading"><span class="font-weight-bolder">Sales report</span>&nbsp;generated</p><small class="notification-text"> Last month sales report generated</small>
                  </div>
                </div></a><a class="d-flex" href="javascript:void(0)">
                <div class="media d-flex align-items-start">
                  <div class="media-left">
                    <div class="avatar bg-light-warning">
                      <div class="avatar-content"><i class="avatar-icon" data-feather="alert-triangle"></i></div>
                    </div>
                  </div>
                  <div class="media-body">
                    <p class="media-heading"><span class="font-weight-bolder">High memory</span>&nbsp;usage</p><small class="notification-text"> BLR Server using high memory</small>
                  </div>
                </div></a>
            </li> --}}
            {{-- <li class="dropdown-menu-footer"><a class="btn btn-primary btn-block" href="javascript:void(0)">Read all notifications</a></li> --}}
          </ul>
        </li>
        <li class="nav-item dropdown dropdown-user"><a class="nav-link dropdown-toggle dropdown-user-link" id="dropdown-user" href="javascript:void(0);" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <div class="user-nav d-sm-flex d-none"><span class="user-name font-weight-bolder">@if($master_user_record){{$master_username}}@else{{$hdrFirstName}}@endif</span><span class="user-status"></span></div><span class="avatar"><img class="round" src="{{$hdrPic}}" alt="avatar" height="40" width="40"><span class="avatar-status-online"></span></span></a>
            
            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdown-user">
            <a class="dropdown-item" href="{{ url('user_profile') }}"><i class="mr-50" data-feather="user"></i> Profile</a>
            <a class="dropdown-item" href="{{ url('get_user_log') }}"><i class="mr-50" data-feather="activity"></i> Activity Log</a>
              @if($master_user_record)
               <a class="dropdown-item" href="{{ route('impersonate_logout',['id'=>$master_user]) }}"><i class="mr-50" data-feather="log-out"></i> Launchpad</a>
              @endif
              <a class="dropdown-item" href="{{ url('logout_user') }}"><i class="mr-50" data-feather="power"></i> Logout</a>
          </div>
          
        </li>
      </ul>
    </div>
  </nav>
@endif
  {{-- <ul class="main-search-list-defaultlist d-none">
    <li class="d-flex align-items-center"><a href="javascript:void(0);">
        <h6 class="section-label mt-75 mb-0">Files</h6></a></li>
    <li class="auto-suggestion"><a class="d-flex align-items-center justify-content-between w-100" href="app-file-manager.html">
        <div class="d-flex">
          <div class="mr-75"><img src="app-assets/images/icons/xls.png" alt="png" height="32"></div>
          <div class="search-data">
            <p class="search-data-title mb-0">Two new item submitted</p><small class="text-muted">Marketing Manager</small>
          </div>
        </div><small class="search-data-size mr-50 text-muted">&apos;17kb</small></a></li>
    <li class="auto-suggestion"><a class="d-flex align-items-center justify-content-between w-100" href="app-file-manager.html">
        <div class="d-flex">
          <div class="mr-75"><img src="app-assets/images/icons/jpg.png" alt="png" height="32"></div>
          <div class="search-data">
            <p class="search-data-title mb-0">52 JPG file Generated</p><small class="text-muted">FontEnd Developer</small>
          </div>
        </div><small class="search-data-size mr-50 text-muted">&apos;11kb</small></a></li>
    <li class="auto-suggestion"><a class="d-flex align-items-center justify-content-between w-100" href="app-file-manager.html">
        <div class="d-flex">
          <div class="mr-75"><img src="app-assets/images/icons/pdf.png" alt="png" height="32"></div>
          <div class="search-data">
            <p class="search-data-title mb-0">25 PDF File Uploaded</p><small class="text-muted">Digital Marketing Manager</small>
          </div>
        </div><small class="search-data-size mr-50 text-muted">&apos;150kb</small></a></li>
  </ul>
  <ul class="main-search-list-defaultlist-other-list d-none">
    <li class="auto-suggestion justify-content-between"><a class="d-flex align-items-center justify-content-between w-100 py-50">
        <div class="d-flex justify-content-start"><span class="mr-75" data-feather="alert-circle"></span><span>No results found.</span></div></a></li>
  </ul> --}}
