FROM openjdk:8
MAINTAINER Mateusz Żółtak <mateusz.zoltak@oeaw.ac.at>

WORKDIR /opt

# Install Karaf
ENV KARAF_VERSION="4.0.7"
RUN wget http://archive.apache.org/dist/karaf/${KARAF_VERSION}/apache-karaf-${KARAF_VERSION}.tar.gz &&\
  tar -xzf apache-karaf-${KARAF_VERSION}.tar.gz &&\
  rm *.tar.gz &&\
  mv apache-karaf-${KARAF_VERSION} /opt/karaf &&\
  /opt/karaf/bin/start

# copy Fedora Camel Toolbox config
COPY root /

ENV JAVA_OPTS="-XX:MaxHeapFreeRatio=20 -XX:MinHeapFreeRatio=10 -XX:GCTimeRatio=20"
CMD ["/opt/karaf/bin/karaf", "server"]

