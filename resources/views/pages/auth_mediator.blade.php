@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Authenticate Account')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
<li class="breadcrumb-item active">Authenticate Account</li>
@endsection

@push('page-style')

<style>
  .auth-wrapper {
    display: -webkit-box;
    display: -webkit-flex;
    display: -ms-flexbox;
    display: flex;
    -webkit-flex-basis: 100%;
    -ms-flex-preferred-size: 100%;
    flex-basis: 100%;
    min-height: 100vh;
    min-height: calc(var(--vh, 1vh) * 100);
    width: 100%
  }

  .auth-wrapper .auth-inner {
    width: 100%
  }

  .auth-wrapper.auth-v1 {
    -webkit-box-align: center;
    -webkit-align-items: center;
    -ms-flex-align: center;
    align-items: center;
    -webkit-box-pack: center;
    -webkit-justify-content: center;
    -ms-flex-pack: center;
    justify-content: center;
    overflow: hidden
  }

  .auth-wrapper.auth-v1 .auth-inner {
    position: relative;
    max-width: 400px
  }

  .auth-wrapper.auth-v1 .auth-inner:before {
    width: 244px;
    height: 243px;
    content: ' ';
    position: absolute;
    top: -40px;
    left: -46px;
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAPQAAADzCAMAAACG9Mt0AAAAAXNSR0IArs4c6QAAAERlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAA9KADAAQAAAABAAAA8wAAAADhQHfUAAAAyVBMVEUAAAD///+AgP+AgP9mZv+AgNWAgP9tbf9gYP+AgP9xcf9mZv+AZuaAgP9dXf90dOhiYv92dv9mZu5mZv93d+53d/9paf94afCAcfFrXvJra/9mZvJzZvJzc/JoaP96b/Rqav91aupsYvV2bOt2bPVxaPZ7cfZqavZyau1waPd4aO9xafBxafh4afB1bfh4avFuZ/F2afJzZvJzZ/N0aPN0bvN3bPR0ae5yZ/R3be93bfR1au9zafBxbPVzavV0a/F0a/ZyafFwaPKZm3nTAAAAQ3RSTlMAAQIEBQYGBwgICQoKCgsLDQ0PDw8PERESExMUFBQWFxgYGhoaGxsdHSAgIiIiIyQlJygqLCwtLi8vLzAzNDU3Nzg7h9vbHgAAA9RJREFUeNrt3ftS2kAUx/Fc1gSyWsErtuJdRDQiiteolb7/QzUoTm07k4AzObuu3/MCez45yWbzT36eZ6b8erO1e1B97baadd+zocJWmg0HaXe/+uqmg2GWtkLT5Lle1m9LdhG2+1lvzuiUO1knEF81yFc1N+35m15kZOGodz1vyLx+v2Lseq/erxtZd/NuweCTtfiwaWLOD5FnsqI7+VnP3y8afnEs3Es/1+H1qvETwuq18B7e6VlwLup1ZM8kWWQBOsrmHL7GVtxvYRZYgQ4ywae61ffsqH5Lbq20bQm6ncp9P2ehJegwE/u+rl95ttSwLrVSc2ANetAU28dSa9Cp2E623bUG3d2VWmn/wBq0XCugQYMGLdVKoOJaoiuok1NdXSW1WAUfRPtRUllflaJf5ZE/O9pXVbZUPTov5c+IDqvtRwStdTgLutoxy6GnGfYb2o+1I2gd+1OiqzfLocvVE7TSDqG1mgodaqfQZbvZC9rXjqG1X45WzqFVKVpk0LLo4lGP0ZGD6KgMnTiITkrQgXYQrYNitHISrYrRsZPouBhdcxJdK0YnTqKTYrR2Eq1BgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRoh9DH59ag86ACoSYOL61B55EUQk1s3VqDzsNHhJpYe7QGncfMSHUxaliCHgcKSXVxeWQJehwdJdXF4dAS9DgkTKqLxuibFeiXODixNi7OrEC/BP+JtbE0WrYA/RrxKNfH2YUF6NegSbk+Gk87xtErN6EsWm88fzeMXpwE9EruLns/l42io4dJFLPo2/Po1w+D6IW7t9Bt2SPx3vOOMfS7eHVZtN54ulg2go56138Ct4XRunE2Ovsmjg46WeddUoUWr6WL0fCoIYgO2/2s91fstDZQjcPL0ePt5flpdXUwqW46uMrS1j95JNpQrW0dHp9UV/uT2m416/8HVGg3qzhpBjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KBBgwYNGjRo0KC/FDpx0pwUo2tOomvF6NhJdFyMVk6iVTE6cBIdeF9vJyvZx/I/AzuIjsrQvoNovwzt4FamSs0Ojrp80PmvoB0zh940pb7azf1yg7t0LIt978uppzbnalfucDW92ZndLPRmKweGPduYJ+zoM5/Dk+gD5NdvLhXXPp88qcUqmEH5G5JZRs6cuxwIAAAAAElFTkSuQmCC)
  }

  .auth-wrapper.auth-v1 .auth-inner:after {
    width: 272px;
    height: 272px;
    content: ' ';
    position: absolute;
    bottom: -40px;
    right: -75px;
    background-image: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAARAAAAEQCAMAAABP1NsnAAAAAXNSR0IArs4c6QAAAERlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAABEKADAAQAAAABAAABEAAAAAAQWxS2AAAAwFBMVEUAAAD///+AgICAgP9VVaqqVf+qqv+AgL+AgP9mZsxmZv+ZZv+AgNWAgP9tbdttbf+Sbf+AYN+AgN+AgP9xceNmZv+AZuaAZv90dOh0dP9qav+AauqAav+AgP92dv9tbf+Abe2Abf93Zu53d+6AcO94afCAcfF5a+R5a/JzZuaAZvKAc/J5bed5bfOAaPN6b/R1auqAavR6ZvV6cPV2bOuAbPV7aPZ2be2AbfZ7au17avZ3Zu53b+57a+97a/d4aO9J6CoeAAAAQHRSTlMAAQICAwMDBAQFBQUGBgcHBwgICAkKCgoLCwwMDAwNDg4ODw8QERITExQUFBUVFhcYGBkZGhobHBwdHR4eHx8gJ5uMWwAAA/FJREFUeNrt2G1XEkEYxvHZNk2xHGzdbKFl0cTwgdSkCKzu7/+t4pw6sAjtjIueE/f8r3fMO35nZnbuy5gVGcvfzJe0rnTfGI+MggGJRUZnbpPIhJKt88nU53JnFULvyISY6KAv8vPj0vr2rYwiE2Z2B9J+uNYcyyQxwWZvaeGH3G4bMjsvI/kcwTC/V+7kLoahlITzQojP3ZFgsJCh7IJQzpX0QFj4uMiY18eDMZ9bZCF9OQahnK6cm/Y7js0sh/LF3Auv1PlQd3MxbdXYIQspV44EEEAAAWTNDAYYkKdJbNMsLzYueZbaZ2iM46RVbHBaiZ9Js+nHEdli42N9XuSen5hGp1CQTuOJQDRsD99N4gMSpYWapNH6IJo83CIeILZQFesEaber79NCWRoukOpNEnW0gXQqD81w6ACxhbrYde7VuFCYeA2QRCNIsgZISyNIqz6IyhPjOjNVIFYniK3dmKU6QdLaJUimEySrDZLrBMlrgxRKU7sxCw/EMe0CAggggADySJCqxixIkKpNEh6IozELD8RxjQACCCCAAPJIkKrGLEgQXqqAAEJjxrQLCCCAAEJjRmNGY8a0CwgggABCYwYIfQgggNCYMe0CAggggNCY0ZjRmDHtAgIIIIAAQmNGHwIIIDRmTLuAAAIIIDRmNGY0Zky7gAACCCCA0JjRhwACCI0Z0y4ggAACCI0ZjRmNGdMuIIAAAgggNGb0IYAAQmPGtAsIIIAAQmNGY0ZjxrQLCCCAAAIIjRl9CCCA0Jgx7QICCCCA0JjRmNGYMe0CAggggABCY0YfAgggNGZMu4AAAgggNGY0ZjRmTLuAAAIIIIDQmNGHAAIIjRnTLiCAAAIIjRmNGY0ZIEy7gAACCCA0ZvQhgABCY8a0CwgggABCY0ZjBgiNGdMuIIAAAgiN2f/Sh+Q6PfLaIJlOkKw2SKoTJK3dmFmdILb2tBvrBIlrg5iWRo+WqQ+SaARJ1gCJAzsxThCN16p1vNurGjNjoo42j07kAHFskoY2kEbl33U0ZgoPjXW+Rl0gkarnahqtDaJKxMPDDWIiNafGenh4gExvVhXfmk7Da6L1AVGxSby2h6MxK79Zk42ea1pJbJ48sU2zDezQ8iy1z6BBwoyjMQsvXp8YQAAhgADilRfyy+wf8WqZZUfGZihvgZiB3FybC+kCUU5XLkAo50C+gbBQdUzkAIVyejIAYfFTI1solHP2HgNCnHn5AYNy4jvpoVB6fVzL91cwzLJ9Lfd7S0jhehxO5H5/yePr1W6gHonI7fJ5ORSR/n6Q2yQanq763zuXU5LJZRKiyD/W9/pjkdPZz0/yJ8fqVyry+qQZDMjJKoDfy8bRVhHhQTwAAAAASUVORK5CYII=);
    z-index: -1
  }

  @media (max-width:575.98px) {

    .auth-wrapper.auth-v1 .auth-inner:after,
    .auth-wrapper.auth-v1 .auth-inner:before {
      display: none
    }
  }

  .auth-wrapper.auth-v2 {
    -webkit-box-align: start;
    -webkit-align-items: flex-start;
    -ms-flex-align: start;
    align-items: flex-start
  }

  .auth-wrapper.auth-v2 .auth-inner {
    overflow-y: auto;
    height: calc(var(--vh, 1vh) * 100)
  }

  .auth-wrapper.auth-v2 .brand-logo {
    position: absolute;
    top: 2rem;
    left: 2rem;
    margin: 0;
    z-index: 1;
    -webkit-box-pack: unset;
    -webkit-justify-content: unset;
    -ms-flex-pack: unset;
    justify-content: unset
  }

  .auth-wrapper .brand-logo {
    display: -webkit-box;
    display: -webkit-flex;
    display: -ms-flexbox;
    display: flex;
    -webkit-box-pack: center;
    -webkit-justify-content: center;
    -ms-flex-pack: center;
    justify-content: center;
    margin: 1rem 0 2rem
  }

  .auth-wrapper .brand-logo .brand-text {
    font-weight: 600
  }

  .auth-wrapper .auth-footer-btn .btn {
    padding: .6rem !important
  }

  .auth-wrapper .auth-footer-btn .btn:not(:last-child) {
    margin-right: 1rem
  }

  .auth-wrapper .auth-footer-btn .btn:focus {
    box-shadow: none
  }

  @media (min-width:1200px) {
    .auth-wrapper.auth-v2 .auth-card {
      width: 400px
    }
  }

  @media (max-width:575.98px) {
    .auth-wrapper.auth-v2 .brand-logo {
      left: 1.5rem;
      padding-left: 0
    }
  }

  .auth-wrapper .auth-bg {
    background-color: #FFF
  }

  .dark-layout .auth-wrapper .auth-bg {
    background-color: #283046
  }

  @media (max-height:625px) {
    .dark-layout .auth-wrapper .auth-inner {
      background-color: #283046
    }

    .auth-wrapper .auth-bg {
      padding-top: 3rem
    }

    .auth-wrapper .auth-inner {
      background-color: #FFF;
      padding-bottom: 1rem
    }

    .auth-wrapper.auth-v2 .brand-logo {
      position: relative;
      left: 0;
      padding-left: 1.5rem
    }
  }


  .pace .pace-progress {
    height: 4px;
  }

  .header-navbar,
  .main-search-list-defaultlist,
  .main-menu,
  .content-header-left,
  .footer,
  .header-navbar-shadow,
  .drag-target {
    display: none !important;
  }

  .app-content {
    width: 100% !important;
    margin: 0 !important;
    padding-top: 0 !important;
  }

  @media(max-width:767px) {
    html body .app-content {
      padding: 0 !important;
    }
  }
</style>

@endpush

@section('page-content')


<div class="auth-wrapper auth-v1 px-2">
  <div class="auth-inner py-2">
    <!-- Register v1 -->
    <div class="card mb-0">
      <div class="card-body">
        <a href="#" class="brand-logo">

          @if($platform=='brightpearl')
          <svg viewBox="0 0 139 95" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" height="28">
            <defs>
              <linearGradient id="linearGradient-1" x1="100%" y1="10.5120544%" x2="50%" y2="89.4879456%">
                <stop stop-color="#000000" offset="0%"></stop>
                <stop stop-color="#FFFFFF" offset="100%"></stop>
              </linearGradient>
              <linearGradient id="linearGradient-2" x1="64.0437835%" y1="46.3276743%" x2="37.373316%" y2="100%">
                <stop stop-color="#EEEEEE" stop-opacity="0" offset="0%"></stop>
                <stop stop-color="#FFFFFF" offset="100%"></stop>
              </linearGradient>
            </defs>
            <g id="Page-1" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
              <g id="Artboard" transform="translate(-400.000000, -178.000000)">
                <g id="Group" transform="translate(400.000000, 178.000000)">
                  <path class="text-primary" id="Path" d="M-5.68434189e-14,2.84217094e-14 L39.1816085,2.84217094e-14 L69.3453773,32.2519224 L101.428699,2.84217094e-14 L138.784583,2.84217094e-14 L138.784199,29.8015838 C137.958931,37.3510206 135.784352,42.5567762 132.260463,45.4188507 C128.736573,48.2809251 112.33867,64.5239941 83.0667527,94.1480575 L56.2750821,94.1480575 L6.71554594,44.4188507 C2.46876683,39.9813776 0.345377275,35.1089553 0.345377275,29.8015838 C0.345377275,24.4942122 0.230251516,14.560351 -5.68434189e-14,2.84217094e-14 Z" style="fill: currentColor"></path>
                  <path id="Path1" d="M69.3453773,32.2519224 L101.428699,1.42108547e-14 L138.784583,1.42108547e-14 L138.784199,29.8015838 C137.958931,37.3510206 135.784352,42.5567762 132.260463,45.4188507 C128.736573,48.2809251 112.33867,64.5239941 83.0667527,94.1480575 L56.2750821,94.1480575 L32.8435758,70.5039241 L69.3453773,32.2519224 Z" fill="url(#linearGradient-1)" opacity="0.2"></path>
                  <polygon id="Path-2" fill="#000000" opacity="0.049999997" points="69.3922914 32.4202615 32.8435758 70.5039241 54.0490008 16.1851325"></polygon>
                  <polygon id="Path-21" fill="#000000" opacity="0.099999994" points="69.3922914 32.4202615 32.8435758 70.5039241 58.3683556 20.7402338"></polygon>
                  <polygon id="Path-3" fill="url(#linearGradient-2)" opacity="0.099999994" points="101.428699 0 83.0667527 94.1480575 130.378721 47.0740288"></polygon>
                </g>
              </g>
            </g>
          </svg>
          <h2 class="brand-text text-primary ms-1">&nbsp;ESB</h2>

          @elseif($platform=='intacct')


          <svg xmlns="http://www.w3.org/2000/svg" width="162" height="30" viewBox="0 0 162 30" fill="none">
            <g clip-path="url(#clip0)">
              <path d="M54.6723 6.14807C49.826 6.14807 46.6528 9.46823 46.6528 14.9443C46.6528 21.7428 51.5062 23.6939 54.9709 23.6939C56.2591 23.6935 57.5339 23.4322 58.7181 22.9257C59.9023 22.4193 60.9714 21.6783 61.8607 20.7474L59.6265 18.5088C59.009 19.0803 58.2847 19.5246 57.4951 19.8162C56.7055 20.1078 55.8661 20.2411 55.0249 20.2084C52.3229 20.2084 50.5024 18.631 50.5024 16.4715H62.5371C62.5371 16.4715 64.6382 6.14807 54.6723 6.14807ZM50.5096 13.5358C50.5096 10.6324 52.7151 9.22389 55.0932 9.22389C57.4714 9.22389 59.6121 10.6612 59.6121 13.5358H50.5096Z" fill="#00DC00" />
              <path d="M10.3367 13.2447C8.79685 13.0507 4.33557 13.2268 4.33557 11.1966C4.33557 9.99285 6.20643 9.34247 7.93337 9.34247C9.74297 9.31527 11.5199 9.82553 13.0387 10.8085L15.352 8.49805C14.6649 7.94469 12.114 6.14807 7.94057 6.14807C4.13409 6.14807 0.712577 8.13514 0.712577 11.3223C0.712577 16.2092 6.62017 16.2092 9.08467 16.4499C10.5778 16.5972 11.9629 16.6978 11.9629 18.1603C11.9629 19.5221 9.87619 20.2767 8.26077 20.2767C5.62718 20.2767 4.05134 19.4143 2.50428 18.0273L0.0146027 20.5175C2.17122 22.5836 5.05306 23.7227 8.04131 23.6903C12.4594 23.6903 15.6866 21.7464 15.6866 17.8297C15.6866 15.2354 13.9381 13.6903 10.3619 13.2447" fill="#00DC00" />
              <path d="M23.0654 6.14805C20.5993 6.13217 18.2021 6.96024 16.2728 8.49444L18.6114 10.8193C19.9075 9.85236 21.4874 9.3407 23.105 9.36401C26.048 9.36401 26.9259 10.5354 26.9259 11.8577V13.507H21.6731C19.7339 13.507 15.9166 13.9957 15.9166 18.2573C15.9166 21.387 17.7155 23.6903 22.0041 23.6903C24.1628 23.6903 25.7854 22.9932 26.9115 21.6457V23.3453H30.7144V12.1128C30.7144 8.18183 28.3038 6.14805 23.0618 6.14805H23.0654ZM26.9367 17.2188C26.9367 20.4528 24.6197 20.2947 22.5473 20.2947C20.475 20.2947 19.1114 19.7377 19.1114 18.2788C19.1114 16.9206 20.1908 16.4822 22.4574 16.4822H26.9367V17.2188Z" fill="#00DC00" />
              <path d="M42.4685 6.51097V8.39383C41.8719 7.6626 41.1138 7.07931 40.2536 6.68968C39.3935 6.30005 38.4546 6.11467 37.5107 6.14805C33.9129 6.14805 32.0457 8.16746 31.4197 10.3198C31.1858 11.1211 31.1031 12.6482 31.1031 14.9048C31.1031 17.0284 31.1246 18.9616 31.8658 20.4492C32.8984 22.5297 35.4132 23.6831 37.5107 23.6831C38.4443 23.7051 39.3715 23.5237 40.2278 23.1513C41.084 22.779 41.8486 22.2247 42.4685 21.5271V22.5943C42.4981 23.109 42.4026 23.6232 42.1902 24.0931C41.9778 24.5629 41.6549 24.9745 41.2489 25.2929C40.3472 26.0107 39.217 26.3805 38.0648 26.3349C36.8092 26.3349 35.6039 25.6486 34.9167 25.1707L32.3587 27.7327C34.0173 29.0765 35.9997 29.9892 38.1404 30.0072C40.2036 30.0611 42.2191 29.3825 43.8285 28.092C45.3576 26.8919 46.3254 24.6317 46.3254 22.235V6.51097H42.4685ZM42.4685 14.912C42.5073 15.8333 42.47 16.7563 42.357 17.6716C42.1231 18.7496 40.8819 20.1869 38.6297 20.1869C38.0648 20.1869 35.1722 19.8635 34.8484 17.3626C34.7576 16.5418 34.7203 15.716 34.7368 14.8904C34.7368 12.9429 34.816 12.3212 34.9167 11.829C35.1146 10.848 36.399 9.40353 38.6297 9.40353C41.3604 9.40353 42.2275 11.2612 42.3642 11.9799C42.4696 12.9463 42.5105 13.9186 42.4865 14.8904" fill="#00DC00" />
              <path d="M74.7552 23.6939H73.1794V0H74.7552V23.6939Z" fill="#8E8E8E" />
              <path d="M92.8376 23.6939H91.233V12.5548C91.233 9.806 90.348 8.20341 87.5309 8.20341C85.1383 8.20341 82.5191 9.77366 81.4362 10.7905V23.6831H79.8316V7.06794H81.2419L81.3391 9.22389C83.2711 7.85127 85.4981 6.73737 87.8151 6.73737C91.3194 6.73737 92.8268 8.76755 92.8268 12.4866L92.8376 23.6939Z" fill="#8E8E8E" />
              <path d="M105.193 23.1046C104.405 23.658 102.8 24.0856 101.62 24.0856C98.5402 24.0856 97.3925 22.81 97.3925 19.8958V8.4765H94.7409V7.06795H97.4033V2.32487L99.0079 2.06256V7.06795H103.728V8.4765H98.9971V19.5688C98.9971 21.5667 99.4576 22.7417 101.717 22.7417C102.779 22.6989 103.814 22.3966 104.732 21.8613L105.193 23.1046Z" fill="#8E8E8E" />
              <path d="M117.871 21.3367C117.871 22.2529 118.033 22.7093 118.886 22.7093C119.232 22.696 119.574 22.6306 119.9 22.5153L120.034 23.5609C119.49 23.8484 118.878 23.9837 118.263 23.9525C116.824 23.9525 116.299 22.8746 116.364 21.8613C114.848 23.3164 112.818 24.1156 110.715 24.0855C106.455 24.0855 105.801 21.139 105.801 19.5688C105.801 14.8257 110.093 14.0064 113.957 14.0064C114.745 14.0064 115.497 14.0388 116.216 14.0711V12.5979C116.216 9.32804 115.4 8.08478 112.259 8.08478C109.474 8.08478 108.391 9.13041 108.031 11.1929L106.491 10.9306C107.017 7.85481 109.046 6.74091 112.388 6.74091C116.288 6.74091 117.861 8.1782 117.861 12.3032L117.871 21.3367ZM116.267 15.4473C115.511 15.3791 114.727 15.3467 113.709 15.3467C110.366 15.3467 107.517 16.0366 107.517 19.4071C107.517 21.2037 108.204 22.7093 110.956 22.7093C111.957 22.7098 112.947 22.5007 113.862 22.0953C114.776 21.6898 115.596 21.0972 116.267 20.3557V15.4473Z" fill="#8E8E8E" />
              <path d="M121.016 15.4473C121.016 9.59035 123.894 6.74091 128.747 6.74091C131.892 6.74091 134.482 8.53753 134.644 12.1308L132.91 12.3284C132.615 9.47896 131.237 8.13868 128.553 8.13868C125.765 8.13868 122.689 9.70892 122.689 15.2066C122.689 21.1283 125.567 22.5691 128.355 22.5691C130.91 22.5691 132.673 21.3906 133.137 18.7711L134.777 19.1017C134.155 22.6662 131.302 24.0747 128.388 24.0747C122.915 24.0855 121.016 20.4851 121.016 15.4473Z" fill="#8E8E8E" />
              <path d="M136.742 15.4473C136.742 9.59035 139.62 6.74091 144.473 6.74091C147.622 6.74091 150.208 8.53753 150.374 12.1308L148.636 12.3284C148.341 9.47896 146.963 8.13868 144.279 8.13868C141.494 8.13868 138.415 9.70892 138.415 15.2066C138.415 21.1283 141.293 22.5691 144.081 22.5691C146.636 22.5691 148.399 21.3906 148.866 18.7711L150.503 19.1017C149.881 22.6662 147.031 24.0747 144.114 24.0747C138.641 24.0855 136.742 20.4851 136.742 15.4473Z" fill="#8E8E8E" />
              <path d="M161.775 23.1046C160.987 23.658 159.383 24.0856 158.203 24.0856C155.123 24.0856 153.975 22.81 153.975 19.8958V8.4765H151.324V7.06795H153.975V2.32487L155.584 2.06256V7.06795H160.3V8.4765H155.584V19.5688C155.584 21.5667 156.041 22.7417 158.3 22.7417C159.362 22.6989 160.397 22.3966 161.315 21.8613L161.775 23.1046Z" fill="#8E8E8E" />
            </g>
            <defs>
              <clipPath id="clip0">
                <rect width="161.775" height="30" fill="white" />
              </clipPath>
            </defs>
          </svg>
          @endif

        </a>

        <h4 class="card-title mb-1 text-center">Enter Authentication Details</h4>
        {{-- <p class="card-text mb-2 text-center">Make your account easy and fun!</p> --}}
        @if($platform=='brightpearl')
        <form method="POST" action="{{url('/ConnectBPOauth')}}" onsubmit="return validateForm('brightpearl');">
          <div class="mb-1">
            <label class="my-label">Account ID</label>
            <input type="text" class="form-control" id="account_id" name="account_id" placeholder="Account ID">
            <span class="field_error">Field value is required</span>
          </div>

          <div class="row justify-content-center pb-1">
            <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
              {{-- <button type="submit" class="btn btn-success waves-effect waves-float waves-light connect-now" data-text='Connect Now' >
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                    </button> --}}
              <button type="submit" class="btn btn-primary waves-effect waves-float waves-light connect-now w-100" data-text='Connect Now'>
                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
              </button>
            </div>
          </div>
        </form>
        @elseif($platform=='intacct')
        <form method="POST" action="{{url('/ConnectIntacctOauth')}}" onsubmit="return validateForm('intacct');">
          <div class="mb-1">
            <label class="my-label">Company ID</label>
            <input type="text" class="form-control" id="account_id" name="account_id" placeholder="Company ID">
            <span class="field_error">Field value is required</span>
          </div>
          <div class="mb-1">
            <label class="my-label">User ID</label>
            <input type="text" class="form-control" id="app_id" name="app_id" placeholder="User ID">
            <span class="field_error">Field value is required</span>
          </div>
          <div class="mb-1">
            <label class="my-label">Password</label>
            <input type="password" class="form-control" id="app_secret" name="app_secret" placeholder="Password">
            <span class="field_error">Field value is required</span>
          </div>

          <div class="row justify-content-center pb-1">
            <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
              {{-- <button type="submit" class="btn btn-success waves-effect waves-float waves-light connect-now" data-text='Connect Now' >
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                    </button> --}}
              <button type="submit" class="btn btn-primary waves-effect waves-float waves-light connect-now w-100" data-text='Connect Now'>
                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
              </button>
            </div>
          </div>
        </form>

        @elseif($platform=='wayfair')

        <form method="POST" action="{{url('/ConnectIntacctOauth')}}" onsubmit="return validateForm('wayfair');">
          <div class="mb-1">
            <label class="my-label">Client ID</label>
            <input type="text" class="form-control" id="client_id" name="client_id" placeholder="Client ID">
            <span class="field_error">Field value is required</span>
          </div>
          <!-- <div class="mb-1">
            <label class="my-label">User ID</label>
            <input type="text" class="form-control" id="app_id" name="app_id" placeholder="User ID">
            <span class="field_error">Field value is required</span>
          </div> -->
          <div class="mb-1">
            <label class="my-label">Client Secret</label>
            <input type="password" class="form-control" id="client_secret" name="client_secret" placeholder="Password">
            <span class="field_error">Field value is required</span>
          </div>

          <div class="row justify-content-center pb-1">
            <div class="col-xl-12 col-sm-12 col-12 my-2 mb-xl-0 text-center">
              {{-- <button type="submit" class="btn btn-success waves-effect waves-float waves-light connect-now" data-text='Connect Now' >
                        <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'>Connect Now</span>
                    </button> --}}
              <button type="submit" class="btn btn-primary waves-effect waves-float waves-light connect-now w-100" data-text='Connect Now'>
                <span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Connect Now </span>
              </button>
            </div>
          </div>
        </form>
        @endif


      </div>
    </div>
    <!-- /Register v1 -->
  </div>
</div>



@endsection
@push('page-script')
<script>
  function validateForm(platform) {
    valid = true;
    $('.field_error').hide();
    if (platform == 'brightpearl') {
      if (!checkvalue($('#account_id'))) {
        showErrorAndFocus($('#account_id'));
        valid = false;
      }
    } else if (platform == 'intacct') {
      if (!checkvalue($('#account_id'))) {
        showErrorAndFocus($('#account_id'));
        valid = false;
      }
      if (!checkvalue($('#app_id'))) {
        showErrorAndFocus($('#app_id'));
        valid = false;
      }
      if (!checkvalue($('#app_secret'))) {
        showErrorAndFocus($('#app_secret'));
        valid = false;
      }
    } else if (platform == 'wayfair') {
      if (!checkvalue($('#client_id'))) {
        showErrorAndFocus($('#client_id'));
        valid = false;
      }
      if (!checkvalue($('#client_secret'))) {
        showErrorAndFocus($('#client_secret'));
        valid = false;
      }
    }

    if (!valid) {
      return false;
    }
    return true;
  }

  function showErrorAndFocus(elem) {
    if (elem.length) {
      elem.next().show();
    }
  }

  function checkvalue(elem) {
    if (elem.length) {
      if (!elem.val().trim()) {
        return false;
      } else {
        return true;
      }
    }
  }
</script>
@endpush